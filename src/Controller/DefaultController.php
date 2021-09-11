<?php

namespace ApManBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DefaultController extends Controller
{
    private $logger;
    private $apservice;
    private $doctrine;
    private $rpcService;
    private $ssrv;
    private $ieparser;

    public function __construct(
	    \Psr\Log\LoggerInterface $logger,
	    \ApManBundle\Service\AccessPointService $apservice,
	    \Doctrine\Persistence\ManagerRegistry $doctrine,
	    \ApManBundle\Service\wrtJsonRpc $rpcService,
	    \ApManBundle\Service\SubscriptionService $ssrv,
	    \ApManBundle\Service\WifiIeParser $ieparser
    )
    {
	    $this->logger = $logger;
	    $this->apservice = $apservice;
	    $this->doctrine = $doctrine;
	    $this->rpcService = $rpcService;
	    $this->ssrv = $ssrv;
	    $this->ieparser = $ieparser;
    }

    /**
     * @Route("/")
     */
    public function indexAction(\ApManBundle\Service\wrtJsonRpc $rpc)
    {
	$logger = $this->logger;
	$apsrv = $this->apservice;
        $doc = $this->doctrine;
	$em = $doc->getManager();

	$neighbors = array();
	$firewall_host = $this->container->getParameter('firewall_url');
	$firewall_user = $this->container->getParameter('firewall_user');
	$firewall_pwd = $this->container->getParameter('firewall_password');

	// read dhcpd leases
	$output = array();
	$result = NULL;
	exec('dhcp-lease-list  --parsable', $lines, $result);
	if ($result == 0) {
		foreach ($lines as $line) {
			if (substr($line,0,4) != 'MAC ') continue;
			$data = explode(' ', $line);
			$neighbors[ $data[1] ]['ip'] = $data[3];
			if ($data[5] != '-NA-') {
				$neighbors[ $data[1] ]['name'] = $data[5];
			} else {
				$name = gethostbyaddr($data[3]);
				if ($name == $data[3]) continue;
				$neighbors[ $data[1] ]['name'] = $name;
			}
		}
	}

	$query = $em->createQuery("SELECT c FROM ApManBundle\Entity\Client c");
	$result = $query->getResult();
	foreach ($result as $client) {
		$mac = $client->getMac();
		$neighbors[ $mac ] = array();
		$neighbors[ $mac ]['name'] = $client->getName();
	}

	if ($firewall_host) {
		$logger->debug('Building MAC cache');
		$session = $rpc->login($firewall_host,$firewall_user,$firewall_pwd);
		if ($session !== false) {
			// Read dnsmasq leases
			$opts = new \stdclass();
			$opts->command = 'cat';
			$opts->params = array('/tmp/dhcp.leases');
			$stat = $session->call('file','exec', $opts);
			if (property_exists($stat, 'stdout') && is_array($stat->stdout)) {
				$lines = explode("\n", $stat->stdout);
				foreach ($lines as $line) {
					$ds = explode(" ", $line);
					if (!array_key_exists(3, $ds)) {
						continue;
					}
					$mac = strtolower($ds[1]);
					if (strlen($mac)) {
						if (array_key_exists($mac, $neighbors)  && array_key_exists('name', $neighbors[$mac])) continue;
						$neighbors[ $mac ] = array('ip' => $ds[2], 'name' => $ds[3]);
					}
				}
			}
			// Read neighbor information
			$opts = new \stdclass();
			$opts->command = 'ip';
			$opts->params = array('-4','neighb');
			$stat = $session->call('file','exec', $opts);
			$lines = explode("\n", $stat->stdout);
			foreach ($lines as $line) {
				$ds = explode(" ", $line);
				if (!array_key_exists(4, $ds)) {
					continue;
				}
				$mac = strtolower($ds[4]);
				if (strlen($mac)) {
					if (array_key_exists($mac, $neighbors)  && array_key_exists('name', $neighbors[$mac])) continue;
					$neighbors[ $mac ] = array('ip' => $ds[0]);
					$cache = $this->get('session')->get('name_cache', null);
					if (!is_array($cache)) {
						$cache = array();
					}
					if (array_key_exists($mac, $cache)) {
						$name = $cache[ $mac ];
						if ($name === false) {
							$logger->debug('skipping because of negative entry: '.$name);
							continue;
						}
						$logger->debug('found '.$name);
					} else {
						$name = gethostbyaddr($ds[0]);
						if ($name == $ds[0]) $name = '';
						if (empty($name)) {
							$cache[ $mac ] = false;
						} else {
							$cache[ $mac ] = $name;
						}

					}
					if ($name) {
						$neighbors[ $mac ]['name'] = $name;
					}
					$this->get('session')->set('name_cache', $cache);
				}
			}
		}
		$logger->debug('MAC cache complete');
	}	
	$aps = $doc->getRepository('ApManBundle:AccessPoint')->findAll();
	$logger->debug('Logging in to all APs');
	$sessions = array();
	$data = array();
	$history = array();
	$macs = array();
	foreach ($aps as $ap) {
		$sessionId = $ap->getName();
		$data[$sessionId] = array();
		$history[$sessionId] = array();
		foreach ($ap->getRadios() as $radio) {
			foreach ($radio->getDevices() as $device) {
				$delat = 0;
				$status = $this->ssrv->getCacheItemValue('status.device.'.$device->getId());
				$ifname = $device->getIfname();
				if ($status === NULL) continue;
				if (!isset($status['info'])) continue;

				$data[$sessionId][$ifname] = array();
				$data[$sessionId][$ifname]['board'] = $this->ssrv->getCacheItemValue('status.ap.'.$ap->getId());
				$data[$sessionId][$ifname]['info'] = $status['info'];
				$data[$sessionId][$ifname]['assoclist'] = array();
				$data[$sessionId][$ifname]['deviceId'] = $device->getId();
				if (array_key_exists('assoclist', $status)) {
					foreach ($status['assoclist']['results'] as $entry) {
						$mac = strtolower($entry['mac']);
						$data[$sessionId][$ifname]['assoclist'][$mac] = $entry;
						$macs[$mac] = true;
					}
				}
				$data[$sessionId][$ifname]['clients'] = array();
				if (array_key_exists('clients', $status)) {
					if (array_key_exists('clients', $status['clients'])) {
						$data[$sessionId][$ifname]['clients'] = $status['clients']['clients'];
					}
				}
				$data[$sessionId][$ifname]['clientstats'] = $status['stations'];

				if (array_key_exists('history', $status) and is_array($status['history']) and array_key_exists(0, $status['history'])) {
					$currentStatus = $status;
					$status = $currentStatus['history'][0];
					if ($status === NULL) continue;
					

					$history[$sessionId][$ifname] = array();
					$history[$sessionId][$ifname]['board'] = $ap->getStatus();
					$history[$sessionId][$ifname]['info'] = $status['info'];
					$history[$sessionId][$ifname]['assoclist'] = array();
					if (array_key_exists('timestamp', $status) && array_key_exists('timestamp', $currentStatus)) {
						$deltat = $currentStatus['timestamp']-$status['timestamp'];
						$history[$sessionId][$ifname]['timedelta'] = $deltat;
					}
					foreach ($status['assoclist']['results'] as $entry) {
						$mac = strtolower($entry['mac']);
						$history[$sessionId][$ifname]['assoclist'][$mac] = $entry;
					}
					$history[$sessionId][$ifname]['clients'] = array();
					if (array_key_exists('clients', $status)) {
						if (array_key_exists('clients', $status['clients'])) {
							$history[$sessionId][$ifname]['clients'] = $status['clients']['clients'];
						}
					}
					$history[$sessionId][$ifname]['clientstats'] = $status['stations'];
				}
			}
		}
	}
	$heatmap = [];
	$query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		ORDER by d.id DESC
	");
	$devices = $query->getResult();
	$keys = [];
	$devById = [];
	foreach ($devices as $device) {
		$devById[ $device->getId() ] = $device;
		foreach ($macs as $mac => $v) {
			$keys[] = 'status.device['.$device->getId().'].probe.'.$mac;
		}
	}

	$probes = $this->ssrv->getMultipleCacheItemValues($keys);
	foreach ($probes as $key => $probe) {
		if (is_null($probe) or !is_object($probe)) {
			continue;
		}
		if (!array_key_exists($probe->address, $heatmap)) {
			$heatmap[ $probe->address ] = array();
		}

		$hme = new \ApManBundle\Entity\ClientHeatMap();
		$hme->setTs($probe->ts);
		$hme->setAddress($probe->address);
		$hme->setDevice($devById[ $probe->device ]);
		$hme->setEvent($probe->event);
		if (property_exists($probe, 'signalstr')) {
			$hme->setSignalstr($probe->signalstr);
		}
		$heatmap[ $probe->address ][] = $hme;
	}

	return $this->render('default/clients.html.twig', array(
		'data' => $data,
		'historical_data' => $history,
		'neighbors' => $neighbors,
		'apsrv' => $apsrv,
		'heatmap' => $heatmap,
		'number_format_bps'
	));
    }

    /**
     * @Route("/disconnect")
     */
    public function disconnectAction(Request $request) {
        $doc = $this->doctrine;
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$mac = $request->query->get('mac','');
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $system
	));
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->reason = 5;
	$opts->deauth = false;
	$opts->ban_time = 10;
        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device, 'del_client', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	
	return $this->redirect($this->generateUrl('apman_default_index'));
    }	    

    /**
     * @Route("/deauth")
     */
    public function deauthAction(Request $request) {
        $doc = $this->doctrine;
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$ban_time = intval($request->query->get('ban_time',0));
	$mac = $request->query->get('mac','');
	$ap = $doc->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $system
	));
	$opts = new \stdClass();
	$opts->addr = $mac;
	$opts->reason = 3;
	$opts->deauth = true;
	$opts->ban_time = $ban_time;
        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$device, 'del_client', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	return $this->redirect($this->generateUrl('apman_default_index'));
    }

    /**
     * @Route("/wnm_disassoc_imminent_prepare")
     */
    public function wnmDisassocImminentPrepare(Request $request) {
	if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$ssid = $this->doctrine->getRepository('ApManBundle:SSID')->findOneBy( array(
		'name' => $request->get('ssid')
	));

	return $this->render('default/wnm_disassoc_imminent.html.twig', 
		array(
			'devices' => $ssid->getDevices(),
			'mac' => $request->get('mac'),
			'system' => $request->get('system'),
			'device' => $request->get('device'),
			'ssid' => $request->get('ssid')
		)
	);
    }	    

    /**
     * @Route("/wnm_disassoc_imminent")
     * https://docs.samsungknox.com/admin/knox-platform-for-enterprise/kbas/kba-115013403768.htm
     */
    public function wnmDisassocImminent(Request $request) {
	if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
		echo "Params missing.\n";
		exit();
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	$opts = new \stdClass();
	$opts->addr = $request->get('mac');
	$opts->duration = 1*20;
	$opts->abridged = true;
	$opts->neighbors = array();

	if ($request->get('target') > 0) {
		$targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy( array(
			'id' => $request->get('target')
		));
		$rrm = $targetDev->getRrm();
		$rrm = json_decode(json_encode($rrm));
		if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                	$this->logger->error('wndDisassocImminent(): Failed to get rrm.');
			return $this->redirect($this->generateUrl('apman_default_index'));
		}
		$opts->neighbors = array($rrm->value[2]);
	}

	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $request->get('system')
	));

        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$request->get('device'), 'wnm_disassoc_imminent', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	return $this->redirect($this->generateUrl('apman_default_index'));
    } 
    /**
     * @Route("/rrm_beacon_req")
     */
    public function rrmBeaconRequest(Request $request) {
	if (empty($request->get('mac')) || empty($request->get('system')) || empty($request->get('device')) || empty($request->get('ssid'))) {
		echo "Params missing.\n";
		exit();
		return $this->redirect($this->generateUrl('apman_default_index'));
	}
	/* {"addr":"08:c5:e1:ad:ca:dd", "op_class":0, "channel":-1, "duration":2,"mode":2,"bssid":"ff:ff:ff:ff:ff:ff", "ssid":"kalnet"} */
	$opts = new \stdClass();
	$opts->addr = $request->get('mac');
	$opts->op_class = 0;
	$opts->channel = -1;
	$opts->duration = 2;
	$opts->mode = 2;
	$opts->bssid = 'ff:ff:ff:ff:ff:ff';
	$opts->ssid = $request->get('ssid');

	if ($request->get('target') > 0) {
		$targetDev = $this->doctrine->getRepository('ApManBundle:Device')->findOneBy( array(
			'id' => $request->get('target')
		));
		$rrm = $targetDev->getRrm();
		$rrm = json_decode(json_encode($rrm));
		if (!is_object($rrm) || !property_exists($rrm, 'value') || !is_array($rrm->value)) {
                	$this->logger->error('rrmBeaconRequest(): Failed to get rrm.');
			return $this->redirect($this->generateUrl('apman_default_index'));
		}
		$opts->neighbors = array($rrm->value[2]);
		$opts->abridged = true;
	}

	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->findOneBy( array(
		'name' => $request->get('system')
	));

        $client = $this->ssrv->getMqttClient();
        if (!$client) {
                $this->logger->error($ap->getName().': Failed to get mqtt client.');
		return $this->redirect($this->generateUrl('apman_default_index'));
        }

        $topic = 'apman/ap/'.$ap->getName().'/command';
	$cmd = $this->rpcService->createRpcRequest(1, 'call', null, 'hostapd.'.$request->get('device'), 'rrm_beacon_req', $opts);
	$this->logger->info('Mqtt(): message to topic '.$topic.': '.json_encode($cmd));
	$res = $client->publish($topic, json_encode($cmd));
	$client->loop(1);
        $client->disconnect();
	return $this->redirect($this->generateUrl('apman_default_index'));
    }	    

    /**
     * @Route("/station")
     */
    public function stationAction(Request $request) {
        $doc = $this->doctrine;
	$em = $doc->getManager();
	$system = $request->query->get('system','');
	$device = $request->query->get('device','');
	$deviceId = $request->query->get('deviceId','');
	$mac = $request->query->get('mac','');
	$output = '';
	$output.="<pre>";
	$output.= "System: '$system'\nDevice: '$device'\n";
	$output.="\n";
	$key = 'status.client['.str_replace(':', '', $mac).'].raw_elements';
	$raw_elements = $this->ssrv->getCacheItemValue($key);
	if (strlen($raw_elements)) {
		//$output.=$raw_elements."\n";
		$ieTags = $this->ieparser->parseInformationElements(hex2bin($raw_elements));
		$output.="Information elements transmitted on probe:\n";
		$output.=print_r($this->ieparser->getResolveIeNames($ieTags),true);
		$output.="\n";
		$ieCaps = $this->ieparser->getExtendedCapabilities($ieTags);
		$output.="Capabilities on probe:\n";
		$output.=print_r($ieCaps,true);
	}

	#print("Device Stats: ".'status.device.'.$deviceId." \n");
	$status = $this->ssrv->getCacheItemValue('status.device.'.$deviceId);
	if (is_array($status) && is_array($status['stations'])) {
		if (isset($status['stations'][$mac])) {
			$output.=print_r($status['stations'][$mac],true);
		}
	}
	if (is_array($status) && is_array($status['clients'])) {
		if (isset($status['clients']['clients'][$mac])) {
			$output.=print_r($status['clients']['clients'][$mac],true);
		}
	}
	if (is_array($status) && isset($status['assoclist']) && is_array($status['assoclist'])) {
		if (isset($status['assoclist']['results']) && is_array($status['assoclist']['results'])) {
			foreach ($status['assoclist']['results'] as $r) {
				if (isset($r['mac']) && strtolower($r['mac']) == strtolower($mac)) {
					$output.="Assoclist Entry:\n";
					$output.=print_r($r,true);
				}
			}
		}
	}
	if (is_array($status) && is_array($status['info'])) {
		$output.= "AP Device Status:\n";
		$output.= print_r($status['info'],true);
	}

	if (is_array($status) && is_array($status['ap_status'])) {
		$output.= "AP hostapd Status:\n";
		$output.= print_r($status['ap_status'],true);
	}

	/*
	$heatmap = [];
	$query = $em->createQuery("SELECT d FROM ApManBundle\Entity\Device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		ORDER by d.id DESC
	");
	$devices = $query->getResult();
	$keys = [];
	$devById = [];
	foreach ($devices as $device) {
		$devById[ $device->getId() ] = $device;
		foreach ($macs as $mac => $v) {
			$keys[] = 'status.device['.$device->getId().'].probe.'.$mac;
		}
	}

	$probes = $this->ssrv->getMultipleCacheItemValues($keys);
	foreach ($probes as $key => $probe) {
		if (is_null($probe) or !is_object($probe)) {
			continue;
		}
		if (!array_key_exists($probe->address, $heatmap)) {
			$heatmap[ $probe->address ] = array();
		}

		$hme = new \ApManBundle\Entity\ClientHeatMap();
		$hme->setTs($probe->ts);
		$hme->setAddress($probe->address);
		$hme->setDevice($devById[ $probe->device ]);
		$hme->setEvent($probe->event);
		if (property_exists($probe, 'signalstr')) {
			$hme->setSignalstr($probe->signalstr);
		}
		$heatmap[ $probe->address ][] = $hme;
	}
	 */


	$query = $em->createQuery("SELECT e FROM \ApManBundle\Entity\Event e
		LEFT JOIN e.device d
		LEFT JOIN d.radio r
		LEFT JOIN r.accesspoint a
		WHERE e.address = :mac
		ORDER by e.ts DESC
	");
	$query->setParameter('mac', $mac);
	$events = $query->getResult();
	$output.= "</pre>";
	$output.= "Events<br>\n";
	#$output.= print_r($events, true);
	$output.= "<table border=1>\n";
	foreach ($events as $event) {
		$output.= "<tr>";
		$output.= "<td>";
		$output.= $event->getTs()->format('Y-m-d H:i:s');
		$output.= "</td>";

		$output.= "<td>";
		$output.= $event->getDevice()->getRadio()->getAccessPoint()->getName();
		$output.= "</td>";

		$output.= "<td>";
		$output.= $event->getDevice()->getRadio()->getConfigBand();
		$output.= "</td>";

		$output.= "<td>";
		$output.= $event->getDevice()->getSsid();;
		$output.= "</td>";

		$output.= "<td>";
		$output.= $event->getType();
		$output.= "</td>";

		$output.= "<td>";
		$output.= $event->getEvent();
		$output.= "</td>";
		$output.="</tr>";
	}
	$output.= "</table>";
	return new Response($output);
    }	    

    /**
     * @Route("/wps_pin_requests")
     */
    public function wpsPinRequests(Request $request) {
	$apsrv = $this->apservice;
	$wpsPendingRequests = $apsrv->getPendingWpsPinRequests();
	return $this->render('ApManBundle:Default:wps_pin_requests.html.twig', array(
	    'wpsPendingRequests' => $wpsPendingRequests
        ));
    }

   /**
     * @Route("/wps_pin_requests_ack")
     */
    public function wpsPinRequestAck(Request $request) {
	// client_uuid=ac998afb-1cea-5cd7-a63c-2f817e3f466b&ap_id=24&ap_if=wap-knet0&wps_pin=XXXX
	
	$ap = $this->doctrine->getRepository('ApManBundle:AccessPoint')->find(
		$request->query->get('ap_id')
	);
	$session = $this->rpcService->getSession($ap);	
	if ($session === false) {
	    	$logger->debug('Failed to log in to: '.$ap->getName());
		return false;
	}
	$opts = new \stdClass();
        $opts->command = 'hostapd_cli';
        $opts->params = array(
		'-i', 
		$request->query->get('ap_if'), 
		'wps_pin', 
		$request->query->get('client_uuid'),
		$request->query->get('wps_pin')
	);
        $opts->env = array('LC_ALL' => 'C');
        $stat = $session->callCached('file','exec', $opts, 5);
	print_r($stat);
	if (!is_object($stat) and !property_exists($stat, 'code')) {
		return false;
	}


	return $this->render('ApManBundle:Default:wps_pin_requests.html.twig', array(
//	    'wpsPendingRequests' => $wpsPendingRequests
        ));
    }
 
    /**
     * @Route("/chtest")
     */
    public function chtest(Request $request) {
	$stdin = fopen('php://stdin', 'r');
	while(!feof($stdin)) {
		$buffer = fgets($stdin,4096);
		print("B: $buffer\n");
		ob_flush();
	}
	exit();
   }

}
