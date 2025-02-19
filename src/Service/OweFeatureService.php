<?php

namespace ApManBundle\Service;

class OweFeatureService implements iFeatureService
{
    public $name = 'owe';
    private $logger;
    private $doctrine;
    private $rpcService;
    private $mqttFactory;
    private $kernel;

    private $map;
    private $feature;

    /**
     * set Services.
     *
     * @return \boolean|\null
     */
    public function setServices(
        \Psr\Log\LoggerInterface $logger,
        \Doctrine\Persistence\ManagerRegistry $doctrine,
        wrtJsonRpc $rpcService,
        \ApManBundle\Factory\MqttFactory $mqttFactory,
        \Symfony\Component\HttpKernel\KernelInterface $kernel
           ) {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->rpcService = $rpcService;
        $this->mqttFactory = $mqttFactory;
        $this->kernel = $kernel;
    }

    /**
     * set Feature.
     *
     * @return \boolean|\null
     */
    public function setFeature(\ApManBundle\Entity\Feature $feature)
    {
        $this->feature = $feature;
    }

    /**
     * set SSID.
     *
     * @return \boolean|\null
     */
    public function setSSID(\ApManBundle\Entity\SSID $ssid)
    {
        $this->ssid = $ssid;
    }

    /**
     * set Device.
     *
     * @return \boolean|\null
     */
    public function setDevice(\ApManBundle\Entity\Device $device)
    {
        $this->device = $device;
    }

    /**
     * set SSIDFeatureMap.
     *
     * @return \boolean|\null
     */
    public function setSSIDFeatureMap(\ApManBundle\Entity\SSIDFeatureMap $map)
    {
        $this->map = $map;
        $this->feature = $map->getFeature();
    }

    /**
     * get Config.
     *
     * @return \array|\null
     */
    public function getConfig(array $config)
    {
        $this->logger->info('OweFeatureService:getConfig(): called.');

        $fcfg = $this->feature->getConfig();
        if (!isset($fcfg['ssid_open'])) {
            $this->logger->error('OweFeatureService:getConfig(): No ssid_open config entry.');

            return $config;
        }
        if (!isset($fcfg['ssid_owe'])) {
            $this->logger->error('OweFeatureService:getConfig(): No ssid_owe config entry.');

            return $config;
        }

        if (!isset($config['encryption'])) {
            $this->logger->error('OweFeatureService:getConfig(): No encryption in device config.');

            return $config;
        }

        $encryption = strtolower($config['encryption']);
        if ('owe' == $encryption) {
            $other_ssid_name = $fcfg['ssid_open'];
            $config['hidden'] = 1;
        } else {
            $other_ssid_name = $fcfg['ssid_owe'];
        }

        // get other SSID
        $em = $this->doctrine->getManager();
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery('SELECT c FROM ApManBundle\Entity\SSIDConfigOption c
		LEFT JOIN c.ssid s
		WHERE
		c.name = :ssid AND c.value = :ssid_name'
        );
        $query->setParameter('ssid', 'ssid');
        $query->setParameter('ssid_name', $other_ssid_name);
        try {
            $other_ssid_config = $query->getSingleResult();
        } catch (\Doctrine\Orm\NoResultException $e) {
            $this->logger->error('OweFeatureService:getConfig(): SSID '.$other_ssid_name.' not found.');

            return $config;
        }
        $other_ssid = $other_ssid_config->getSSID();

        //echo "SSID: ".$config['ssid']."\n";
        //echo "Other SSID: ".$other_ssid_name."\n";
        $query = $em->createQuery(
            'SELECT d
			FROM ApManBundle:Device d
			WHERE d.ssid = :ssid AND d.radio = :radio'
        );
        $query->setParameter('ssid', $other_ssid);
        $query->setParameter('radio', $this->device->getRadio());
        try {
            $other_device = $query->getSingleResult();
        } catch (\Doctrine\Orm\NoResultException $e) {
            $this->logger->error('OweFeatureService:getConfig(): No device found for SSID '.$other_ssid_name.' and radio '.$this->device->getRadio()->getName());

            return $config;
        }

        if (strlen($other_device->getIfname())) {
            $config['owe_transition_ifname'] = $other_device->getIfname();
        } elseif (strlen($other_device->getAddress())) {
            $config['owe_transition_ssid'] = $other_ssid_name;
            $config['owe_transition_bssid'] = $other_device->getAddress();
        } else {
            $this->logger->error('OweFeatureService:getConfig(): Failed to get other device ifname or address.');
        }

        return $config;
    }

    /**
     * apply implementation specific constraints.
     *
     * @return \boolean|\null
     */
    public function applyConstraints()
    {
        $this->logger->info('OweFeatureService:applyConstraints(): called.');
        $em = $this->doctrine->getManager();
        $qb = $em->createQueryBuilder();
        $query = $em->createQuery(
            'SELECT m
			FROM ApManBundle:SSIDFeatureMap m
			WHERE m.feature = :feature
			AND m.id != :mapid'
        );
        $query->setParameter('feature', $this->feature);
        $query->setParameter('mapid', $this->map->getId());
        $maps = $query->getResult();
        if (!count($maps)) {
            $this->logger->info('OweFeatureService:applyConstraints(): owe map missing');
            $this->setupOweSsid();
        }
        foreach ($maps as $map) {
            $this->logger->info('OweFeatureService:applyConstraints(): loop.');
        }

        $this->logger->info('OweFeatureService:applyConstraints(): finished.');
    }

    private function setupOweSsid()
    {
        $this->logger->info('OweFeatureService:applyConstraints(): owe map missing');
        $em = $this->doctrine->getManager();
        $open_ssid = $this->device->getSsid();
        $owe_ssid = clone $open_ssid;
        $owe_ssid->setName($open_ssid->getName().' Secure');
        $em->persist($owe_ssid);
        $map = new \ApManBundle\Entity\SSIDFeatureMap();
        $map->setSsid($owe_ssid);
        $map->setFeature($this->feature);
        $map->setPriority(2);
        $map->setConfig(['owe' => true]);
        $map->setName($this->map->getName().' OWE');
        $em->persist($map);
        $em->flush();
        foreach ($open_ssid->getConfigOptions() as $option) {
            $new = clone $option;
            $new->setSsid($owe_ssid);
            if ('ssid' == $new->getName()) {
                $new->setValue($new->getValue().' Secure');
            }
            if ('encryption' == $new->getName()) {
                $new->setValue('owe');
            }
            if ('ifname' == $new->getName()) {
                $new->setValue($new->getValue().'o');
            }
            $owe_ssid->addConfigOption($new);
            $em->persist($new);
        }
        $this->logger->info('OweFeatureService:applyConstraints(): XXX');
        $devmap = [];
        $devmapr = [];
        foreach ($open_ssid->getDevices() as $md) {
            $radio = $md->getRadio();
            $device = new \ApManBundle\Entity\Device();
            $device->setName($radio->getName().'_'.str_replace([' ', '-', '+', '/', '*', '$'], '_', $owe_ssid->getName()));
            $device->setRadio($radio);
            $device->setSSID($owe_ssid);
            $device->setAddress(exec($this->kernel->getProjectDir().'/bin/randmac.pl'));
            $md->setAddress(exec($this->kernel->getProjectDir().'/bin/randmac.pl'));
            $device->setIfname('w-r'.$radio->getId().'-s'.$owe_ssid->getId());
            $em->persist($device);
            $em->persist($md);
            $em->flush();
            $devmap[$md->getId()] = $device->getId();
            $devmapr[$device->getId()] = $md->getId();

            $this->logger->info('OweFeatureService:applyConstraints(): Added Device '.$device->getName().' for SSID '.$owe_ssid->getName());
        }
        $this->map->setConfig(['owe' => false, 'devmap' => $devmap]);
        $map->setConfig(['owe' => true, 'devmap' => $devmapr]);
        $em->persist($this->map);
        $em->flush();
        $this->logger->info('OweFeatureService:applyConstraints(): cloned ssid.');
    }

    public function getAdditionalConfig(array $config)
    {
        return null;
    }
}
