<?php

namespace ApManBundle\Entity;

/**
 * Device
 */
class Device
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var string
     */
    private $name;

    /**
     * @var array
     */
    private $config;

    /**
     * @var \ApManBundle\Entity\Radio
     */
    private $radio;

    /**
     * @var \ApManBundle\Entity\SSID
     */
    private $ssid;

    /**
     * get Status
     * @return \string
     */
    public function getStatus()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$ifname = $config['ifname'];
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->name = $config['ifname'];
	$data = $session->callCached('network.device','status', null, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (isset($data->$ifname)) {
		$res = 'Up: '.$data->$ifname->up?'Up':'Down';
		return $res;
	}

	$opts = new \stdclass();
	$opts->command = 'ip';
	$opts->params = array('-s', 'link', 'show');
	$opts->env = array('LC_ALL' => 'C');
	$stat = $session->callCached('file','exec', $opts, 5);
	if (isset($stat->code) && $stat->code) {
		return '-';
	}
	$lines = explode("\n", $stat->stdout);
	foreach ($lines as $id => $line) {
		$line = trim($line);
		if (strpos($line, ' '.$ifname.':')!== false) {
			if (strpos($line, ',UP')!== false) {
				return 'Up';
			}
			return 'Down';
		}
	}
	return 'Down';
    }

    /**
     * get StatisticsTransmit
     * @return \integer|\string
     */
    public function getStatisticsTransmit()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$ifname = $config['ifname'];
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->name = $config['ifname'];
	$data = $session->callCached('network.device','status', null, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (isset($data->$ifname->statistics)) {
		return sprintf('%d',$data->$ifname->statistics->tx_bytes);
	}

	$opts = new \stdclass();
	$opts->command = 'ip';
	$opts->params = array('-s', 'link', 'show');
	$opts->env = array('LC_ALL' => 'C');
	$stat = $session->callCached('file','exec', $opts, 5);
	if (isset($stat->code) && $stat->code) {
		return '-';
	}
	$lines = explode("\n", $stat->stdout);
	$found = false;
	$foundHead = false;
	foreach ($lines as $id => $line) {
		$line = trim($line);
		if (strpos($line, ' '.$ifname.':')!== false) {
			$found = true;
			continue;
		}
		if (!$found) continue;
		if (substr($line,0,9)  == 'TX: bytes') {
			$foundHead = true;
			continue;
		}
		if (!$foundHead) continue;
		$x = explode(' ', $line);
		return sprintf('%d',$x[0]);
	}
	return '-';
    }

    /**
     * get statisticsReceive
     * @return \integer|\string
     */
    public function getStatisticsReceive()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$ifname = $config['ifname'];
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->name = $config['ifname'];
	$data = $session->callCached('network.device','status', null, 2);
	if ($data === false) {
		return 'AP Offline';
	}
	if (isset($data->$ifname->statistics)) {
		return sprintf('%d',$data->$ifname->statistics->rx_bytes);
	}

	$opts = new \stdclass();
	$opts->command = 'ip';
	$opts->params = array('-s', 'link', 'show');
	$opts->env = array('LC_ALL' => 'C');
	$stat = $session->callCached('file','exec', $opts, 2);
	if (isset($stat->code) && $stat->code) {
		return '-';
	}
	$lines = explode("\n", $stat->stdout);
	$found = false;
	$foundHead = false;
	foreach ($lines as $id => $line) {
		$line = trim($line);
		if (empty($line)) continue;
		if (strpos($line, ' '.$ifname.':')!== false) {
			$found = true;
			continue;
		}
		if (!$found) continue;
		if (substr($line,0,9)  == 'RX: bytes') {
			$foundHead = true;
			continue;
		}
		if (!$foundHead) continue;
		$x = explode(' ', $line);
		return sprintf('%d',$x[0]);
	}
	return '-';
    }

    public function getClients($useArray = false)
    {
        $config = $this->getConfig();
	if (!isset($config['ifname'])) {
		if ($useArray) return array();
	    	return 'No ifname';
		return;
	}
	$ifname = $config['ifname'];
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		if ($useArray) return array();
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $config['ifname'];
	$data = $session->callCached('iwinfo','assoclist', $opts , 2);
	if ($data === false) {
		if ($useArray) return array();
		return '-';
	}
	if (!isset($data->results)) {
		if ($useArray) return array();
		return '-';
	}
	$res = array();
	foreach ($data->results as $client) {
		if (isset($client->mac)) {
			$res[] = $client->mac;
		}
	}
	if (!count($res)) {
		if ($useArray) return array();
		return '-';
	}
	if ($useArray) return $res;
	return join(' ',$res);
    }

    /**
     * get model
     */
    public function getChannel()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $config['ifname'];
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->channel)) {
		return 'No Channel';
	}
	return $data->channel;
    }

    /**
     * get model
     */
    public function getTxPower()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $config['ifname'];
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->txpower)) {
		return 'No TX Power';
	}
	return $data->txpower;
    }

    /**
     * get model
     */
    public function getHwMode()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $config['ifname'];
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->hwmodes)) {
		return 'No hwmodes';
	}
	return join('', $data->hwmodes);
    }

    /**
     * get model
     */
    public function getHtMode()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
	    	return 'No ifname';
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return '-';
	}
	$opts = new \stdClass();
	$opts->device = $config['ifname'];
	$data = $session->callCached('iwinfo','info', $opts, 4);
	if ($data === false) {
		return '-';
	}
	if (!isset($data->hwmodes)) {
		return 'No hwmodes';
	}
	return join(', ', $data->htmodes);
    }

    /**
     * get rrm_own
     */
    public function getRrmOwn()
    {
	$config = $this->getConfig();
	if (!isset($config['ifname'])) {
		return;
	}
	$session = $this->getRadio()->getAccessPoint()->getSession();
	if ($session === false) {
		return null;
	}
	$data = $session->callCached('hostapd.'.$config['ifname'], 'rrm_nr_get_own', null);
	if ($data === false) {
		return null;
	}
	if (is_object($data) && property_exists($data, 'value')) {
		return $data->value;
	}
	return null;
    }

    /**
     * get IsEnabled
     * @return \boolean
     */
    public function getIsEnabled()
    {
	if (!$this->getSSID()->getIsEnabled()) {
		return false;
	}
	$config = $this->getConfig();
	if (!isset($config['disabled'])) {
	    	return true;
	}
	if (intval($config['disabled'])) {
		return false;
	}
	return true;
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     *
     * @return Device
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set config
     *
     * @param array $config
     *
     * @return Device
     */
    public function setConfig($config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get config
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Set radio
     *
     * @param \ApManBundle\Entity\Radio $radio
     *
     * @return Device
     */
    public function setRadio(\ApManBundle\Entity\Radio $radio)
    {
        $this->radio = $radio;

        return $this;
    }

    /**
     * Get radio
     *
     * @return \ApManBundle\Entity\Radio
     */
    public function getRadio()
    {
        return $this->radio;
    }

    /**
     * Set ssid
     *
     * @param \ApManBundle\Entity\SSID $ssid
     *
     * @return Device
     */
    public function setSsid(\ApManBundle\Entity\SSID $ssid)
    {
        $this->ssid = $ssid;

        return $this;
    }

    /**
     * Get ssid
     *
     * @return \ApManBundle\Entity\SSID
     */
    public function getSsid()
    {
        return $this->ssid;
    }
}
