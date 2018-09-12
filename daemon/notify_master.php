<?php

/*
*订单支付成功通知守护进程脚本
*/

require(dirname(__FILE__) . '/lib/NotifyServ.class.php');
require(dirname(__FILE__) . '/lib/KLogger.php');

$notify_que_key = 'order_notify_que';


/**
* 连接Redis
*/
function RedisConnect()
{
	$redis = new Redis();
	$redis->connect('localhost');
	return $redis;
}

/**
* 获取正在执行的任务数
*/
function numberOfWorker()
{
	$handle = popen('ps -ef | grep notify_master.php | grep -v grep | wc -l', 'r');
	$pnum = fread($handle, 8);
	fclose($handle);
	return $pnum;
}

/**
* 发送通知
*/
function notify($url, $data)
{
	$curl = curl_init(); // 启动一个CURL会话
	curl_setopt($curl, CURLOPT_URL, $url); // 要访问的地址
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); // 从证书中检查SSL加密算法是否存在
	curl_setopt($curl, CURLOPT_POST, 1); // 发送一个常规的Post请求
	curl_setopt($curl, CURLOPT_POSTFIELDS, $data); // Post提交的数据包
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // 获取的信息以文件流的形式返回
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30); 
	curl_setopt($curl, CURLOPT_TIMEOUT, 30);
	$result = curl_exec($curl);
	curl_close($curl);
	return $result;
}

/**
* 获取推送详细信息
*/
function getNotifyInfo($mysqli, $orderId)
{
	$result = '';
	$res = $mysqli->query('SELECT order_id, amount, account, notify_url, user_id FROM notify_queue WHERE order_id=' . $orderId);
	while ($row = $res->fetch_assoc()) {
		$result = $row;
	}
	mysqli_free_result($res);
	return $result;
}


/**
* 更新推送次数和时间
*/
function upNotifyQueNumAndTime($mysqli, $num, $time, $orderId)
{
	$mysqli->query('UPDATE notify_queue SET notify_num=' . $num . ',notify_time=' . $time . ' WHERE order_id=' . $orderId);
}


/**
* 删除队列条目
*/
function delNotifyQueByOrderId($mysqli, $orderId)
{
	$mysqli->query('UPDATE notify_queue SET notify_status=1 WHERE order_id=' . $orderId);
}

pcntl_signal(SIGCHLD, SIG_IGN);

while (true) {
	$numOfWorker = numberOfWorker();
	if ($numOfWorker < 100) {
		$redis = RedisConnect();
		$lsize = $redis->lSize($notify_que_key);
		if ($lsize > 0) {
			$item = $redis->lPop($notify_que_key);
			$json = json_decode($item, true);
			if ($json['num'] == 0) {
				$mysqli = new mysqli('localhost', 'root', '', 'test');
				$info = getNotifyInfo($mysqli, $json['order_id']);
				if (empty($info)) {
					continue;
				}
				$tmpData = array(
					//some thing to push
				);
				$notifyData = json_encode($tmpData);
				$notify = new NotifyServ;
				$sign = $notify->sign($notifyData);
				$cryptedData = $notify->encrypt($notifyData);
				$json['post_data'] = 'notify_data=' . urlencode($cryptedData) . '&sign=' . urlencode($sign);
			}
			$dur = time() - $json['time'];
			$continue = false;
			if ($json['num'] > 0) {
				switch ($json['num']) {
					case 1:
						if ($dur < 60) {
							$continue = true;
						}
						break;
					
					case 2:
						if ($dur < 180) {
							$continue = true;
						}
						break;
					case 3:
						if ($dur < 300) {
							$continue = true;
						}
						break;
					case 4:
						if ($dur < 1800) {
							$continue = true;
						}
						break;
					case 5:
						if ($dur < 3600) {
							$continue = true;
						}
						break;
				}
			}
			if ($continue) {
				$item = json_encode($json);
				$redis->rPush($notify_que_key, $item);
				usleep(1000000 / $lsize);
				continue;
			}

			//更新时间和推送次数, 添加队列, 继续推送
			$json['time'] = time();
			$json['num'] += 1;

			$mysqli = new mysqli('localhost', 'root', '', 'test');

			//已经通知过5次, 完成通知逻辑
			if ($json['num'] > 5) {
				delNotifyQueByOrderId($mysqli, $json['order_id']);
				// continue;
			} else {
				upNotifyQueNumAndTime($mysqli, $json['num'], $json['time'], $json['order_id']);

				//添加到队列继续
				// $item = json_encode($json);
				// $redis->rPush($notify_que_key, $item);
			}
			

			//记录日志
			// $info = 'order_id:' . $json['order_id'] . ', notify_num:' . $json['num'] . ', notify_url:' . $json['url'] . ", user_id:" . $json['user_id'];

			// $log = KLogger::instance('/data/log/pay_notify', KLogger::INFO);
			// $log->logInfo($info);

			$pid = pcntl_fork();
			if ($pid == -1) {
				$log->logInfo('fork error');
				continue;
			} else if ($pid == 0) {
				$result = notify($json['url'], $json['post_data']);

				//拼凑日志信息
				$info = 'xxxx result is ' . $result;
				if (trim($result) == "success") {
					$mysqli = new mysqli('localhost', 'root', '', 'test');
					delNotifyQueByOrderId($mysqli, $json['order_id']);
				} else {
					if ($json['num'] <= 5) {
						$redis = RedisConnect();
						//添加到队列继续
						$item = json_encode($json);
						$redis->rPush($notify_que_key, $item);
					}
				}

				$log = KLogger::instance('/data/log/pay_notify', KLogger::INFO);
				$log->logInfo($info);

				posix_kill(getmypid(), 9);
			}
		}
	}
	sleep(1);
}

?>
