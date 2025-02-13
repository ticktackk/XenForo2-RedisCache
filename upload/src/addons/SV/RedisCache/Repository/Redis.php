<?php

namespace SV\RedisCache\Repository;

use Credis_Client;
use XF\Mvc\Entity\Repository;
use XF\Mvc\Reply\View;

class Redis extends Repository
{

    /**
     * @return Redis|Repository
     */
    public static function instance()
    {
        return \XF::repository('SV\RedisCache:Redis');
    }

    /**
     * Inserts redis info parameters into a view.
     *
     * @param View $view
     * @param      $slaveId
     */
    public function insertRedisInfoParams(View $view, $slaveId)
    {
        if ($cache = \XF::app()->cache())
        {
            if ($cache instanceof \SV\RedisCache\Redis &&
                $credis = $cache->getCredis(false))
            {
                $useLua = $cache->useLua();

                $this->addRedisInfo($view, $credis->info(), $useLua);
                $redisInfo = $view->getParam('redis');
                $slaves = $redisInfo['slaves'];

                $config = \XF::app()->config();
                $database = empty($config['cache']['config']['database']) ? 0 : (int)$config['cache']['config']['database'];
                $password = empty($config['cache']['config']['password']) ? null : $config['cache']['config']['password'];
                $timeout = empty($config['cache']['config']['timeout']) ? null : $config['cache']['config']['timeout'];
                $persistent = empty($config['cache']['config']['persistent']) ? null : $config['cache']['config']['persistent'];
                $forceStandalone = empty($config['cache']['config']['force_standalone']) ? null : $config['cache']['config']['force_standalone'];

                if (isset($slaves[$slaveId]))
                {
                    $slaveDetails = $slaves[$slaveId];
                    // query the slave for stats
                    $slaveClient = new Credis_Client($slaveDetails['ip'], $slaveDetails['port'], $timeout, $persistent, $database, $password);
                    if ($forceStandalone)
                    {
                        $slaveClient->forceStandalone();
                    }
                    $this->addRedisInfo($view, $slaveClient->info(), $useLua);

                    $paramItem = ['redis' => ['slaveId' => $slaveId]];
                    $view->setParams($paramItem, true);
                }
            }
        }
    }

    /**
     * Processes redis info and adds as parameter to view.
     *
     * @param View  $response
     * @param array $data
     * @param bool  $useLua
     */
    private function addRedisInfo(View $response, array $data, $useLua = true)
    {
        $database = 0;
        $slaves = [];
        $db = [];

        if (!empty($data))
        {
            $config = \XF::app()->config();
            if (!empty($config['cache']['config']['database']))
            {
                $database = (int)$config['cache']['config']['database'];
            }

            foreach ($data as $key => &$value)
            {
                if (preg_match('/^db(\d+)$/i', $key, $matches))
                {
                    $index = $matches[1];
                    unset($data[$key]);
                    $list = explode(',', $value);
                    $dbStats = [];
                    foreach ($list as $item)
                    {
                        $parts = explode('=', $item);
                        $dbStats[$parts[0]] = $parts[1];
                    }

                    $db[$index] = $dbStats;
                }
            }
            // got slaves
            if (isset($data['connected_slaves']) && isset($data['master_repl_offset']))
            {
                foreach ($data as $key => &$value)
                {
                    if (preg_match('/^slave(\d+)$/i', $key, $matches))
                    {
                        $index = $matches[1];
                        unset($data[$key]);
                        $list = explode(',', $value);
                        $dbStats = [];
                        foreach ($list as $item)
                        {
                            $parts = explode('=', $item);
                            $dbStats[$parts[0]] = $parts[1];
                        }

                        $slaves[$index] = $dbStats;
                    }
                }
            }
        }

        $config = \XF::app()->config();
        $data['serializer'] = empty($config['cache']['config']['serializer']) ? 'php' : $config['cache']['config']['serializer'];
        $data['slaves'] = $slaves;
        $data['db'] = $db;
        $data['db_default'] = $database;
        $data['lua'] = $useLua;
        $data['phpredis'] = phpversion('redis');
        $data['HasIOStats'] = isset($data['instantaneous_input_kbps']) && isset($data['instantaneous_output_kbps']);
        $response->setParam('redis', $data);
    }
}
