<?php

/**
 * 守护进程php  
 * 从redis队列 循环取数据post的远程接口上
 * 没有数据休息1秒钟 sleep
 */
header("Content-type: text/html; charset=utf-8");
require_once(dirname(__FILE__) . '/../HttpHelper.php');

set_time_limit(0);
//============start 配置===================
$config = include (dirname(__FILE__) . '/../../protected/config/main.php');
$redis_config = $config['params']['redis'];
$mysql_config = $config['components']['db'];
$mysql_slaves_config = $config['components']['db']['slaves'];
$key = array_rand($mysql_slaves_config); //随机取一个slave key
$mysql_slave_config = $mysql_slaves_config[$key]; //得到一个slave配置
date_default_timezone_set('Asia/shanghai'); //设置时区
//============end 配置===================
//=========连接redis=====
$redis = new redis();
$redis->connect($redis_config['host'], $redis_config['port']);
//============end 连接redis
//===========================================================================================================================
//pop 数据
$key = 'cn.test:queue:toup_wait_queue';
$key_fail = 'cn.test:queue:toup_wait_queue_fail';
while (true) {
    while (true) {
        $pop_data = $redis->lPop($key);
        if (!$pop_data)
            break;
        echo '====================================' . PHP_EOL;
        echo '==' . date('Y-m-d H:i:s') . "==" . PHP_EOL;
        echo "reids取得数据为:$pop_data" . PHP_EOL;
        $app_arr = json_decode($pop_data, true);
        if (empty($app_arr['app_info']) || empty($app_arr['app_info'])) {
            echo "not found" . PHP_EOL;
            continue;
        }
        
        try {
           //do some thing
        } catch (Exception $e) {
            print $e->getMessage();
        }
       
        //post 接口数据
        $post = array(
            
        );
        try {
            $r = up($post);
        } catch (Exception $e) {
            print $e->getMessage();
        }
        if ($r) {
            echo "success" . PHP_EOL;
        } else {
            echo "fail" . PHP_EOL;
            //把$pop_data roll back redis
            $redis->Lpush($key_fail, json_encode($app_arr));
        }
        //释放资源
        unset($pop_data);
        unset($app_arr);
        unset($app_info);
        unset($post);
    }
    while (true) {
        $pop_data_fail = $redis->lPop($key_fail);
        if (!$pop_data_fail)
            break;
        $redis->Lpush($key, $pop_data_fail);
        //释放资源
        unset($pop_data_fail);
    }
    sleep(1);
}
exit();



?>
