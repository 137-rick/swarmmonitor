<?php
function getSwarmContainer($ip,$port)
{
	//初始化
	$ch = curl_init();
	//设置选项，包括URL
	curl_setopt($ch, CURLOPT_URL, "http://$ip:$port/containers/json?all=1");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_TIMEOUT,60);
	//执行并获取HTML文档内容
	$output = curl_exec($ch);
	//释放curl句柄
	curl_close($ch);
	$output = json_decode($output, true);
	return $output;
}

function getContainerInspect($ip,$port,$id)
{
	//初始化
	$ch = curl_init();
	//设置选项，包括URL
	curl_setopt($ch, CURLOPT_URL, "http://$ip:$port/containers/$id/json");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_TIMEOUT,60);
	//执行并获取HTML文档内容
	$output = curl_exec($ch);
	//释放curl句柄
	curl_close($ch);
	$output = json_decode($output, true);
	return $output;
}

function getCadvistor($ip,$port,$dname){
	//初始化
	$ch = curl_init();
	//设置选项，包括URL
	curl_setopt($ch, CURLOPT_URL, "http://$ip:$port/api/v1.2/docker$dname");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_TIMEOUT,60);
	//执行并获取HTML文档内容
	$output = curl_exec($ch);
	//释放curl句柄
	curl_close($ch);
	$output = json_decode($output, true , 512 , JSON_BIGINT_AS_STRING);
	return $output;
}

function getAliveContainerList($ip,$port){

	$containerList = getSwarmContainer($ip,$port);
	if(!empty($containerList)) {
		$nowlist = array();

		foreach($containerList as $key=>$container){

			$id = substr($container["Id"],0,12);

			$info = array(
					"id" => $container["Id"],
					"name" => $container["Names"][0],
					"cmd" => $container["Command"],
					"image" => $container["Image"],
				     );

			$inspect = getContainerInspect($ip,$port,$id);
			$info["dname"] = $inspect["Name"];
			$info["ip"] = $inspect["Node"]["IP"];
			$info["restartCount"] = $inspect["RestartCount"];
			if($inspect["State"]["Running"]){
				$info["running"] = 1;
			}else{
				$info["running"] = 0;
			}

			$info["exitcode"] = $inspect["State"]["ExitCode"];
			$info["error"] = $inspect["State"]["Error"];

			$performance =  getCadvistor($ip,"8085",$inspect["Name"]);
			if(!empty($performance))
			{
				$performance = end($performance);

				// memory usage
				$info["stat"]["memory.usage.bytes"] = $performance["stats"][0]["memory"]["usage"];

				//memory total percent
				$info["stat"]["memory.total.percent"] = bcdiv($performance["stats"][0]["memory"]["usage"], $inspect["Node"]["Memory"],2) ;;

				//memory limit percent
				$memlimit = $performance["spec"]["memory"]["limit"];
				if($memlimit == "18446744073709551615")
				{
					$info["stat"]["memory.limit.percent"] = -1;
				}else{
					$info["stat"]["memory.limit.percent"] = bcdiv($performance["stats"][0]["memory"]["usage"], $performance["spec"]["memory"]["limit"],2) ;
				}

				//some state was less
				$stepcount = 1;

				//cpu usage
				$cpuusage1 = $performance["stats"][0]["cpu"]["usage"]["total"];
				if(count($performance["stats"]) < 11)
				{
					$cpuusage2 = $performance["stats"][1]["cpu"]["usage"]["total"];
					$stepcount = 1;
				}else{
					$cpuusage2 = $performance["stats"][9]["cpu"]["usage"]["total"];
					$stepcount = 10;
				}

				$cpucount =  count($performance["stats"][1]["cpu"]["usage"]["per_cpu_usage"]);

				if($cpucount ==0) $cpucount = 1;
				$cpuusage = bcsub($cpuusage2,$cpuusage1);
				//decrease the count
				$cpuusage = bcsub($cpuusage,$stepcount);
				//cpu usage percent
				$cpuusage = bcdiv($cpuusage,$cpucount*10000000,2);
				$info["stat"]["cpu.usage.percent"] = $cpuusage;

				//disk io
				$iousage = $performance["stats"][0]["diskio"]["io_service_bytes"][0]["stats"];
				$info["stat"]["disk.io.read.bytes"] = $iousage["Read"];
				$info["stat"]["disk.io.write.bytes"] = $iousage["Write"];

				$stepcount = 1;
				//network
				$network1 =  $performance["stats"][0]["network"];
				if(count( $performance["stats"])< 11)
				{
					$stepcount = 1;
					$network2 = $performance["stats"][1]["network"];
				}else{
					$stepcount = 10;
					$network2 = $performance["stats"][9]["network"];
				}

				$info["stat"]["net.in.bytes"] = bcsub($network2["rx_bytes"] , $network1["rx_bytes"]);
				$info["stat"]["net.in.packets"] = bcsub($network2["rx_packets"] , $network1["rx_packets"]);
				$info["stat"]["net.in.errors"] = bcsub($network2["rx_errors"] , $network1["rx_errors"]);
				$info["stat"]["net.in.dropped"] = bcsub($network2["rx_dropped"] , $network1["rx_dropped"]);

				$info["stat"]["net.out.bytes"] = bcsub($network2["tx_bytes"] , $network1["tx_bytes"]);
				$info["stat"]["net.out.packets"] = bcsub($network2["tx_packets"] , $network1["tx_packets"]);
				$info["stat"]["net.out.errors"] = bcsub($network2["tx_errors"] , $network1["tx_errors"]);
				$info["stat"]["net.out.dropped"] = bcsub($network2["tx_dropped"] , $network1["tx_dropped"]);
				//div step to avg
				$info["stat"]["net.in.bytes"]  =  bcdiv($info["stat"]["net.in.bytes"],$stepcount);
				$info["stat"]["net.in.packets"] =  bcdiv($info["stat"]["net.in.packets"],$stepcount);
				$info["stat"]["net.in.errors"]  =  bcdiv($info["stat"]["net.in.errors"],$stepcount);
				$info["stat"]["net.in.dropped"] =  bcdiv($info["stat"]["net.in.dropped"],$stepcount);
				$info["stat"]["net.out.bytes"]  =  bcdiv($info["stat"]["net.out.bytes"],$stepcount);
				$info["stat"]["net.out.packets"] =  bcdiv($info["stat"]["net.out.packets"],$stepcount);
				$info["stat"]["net.out.errors"]  =  bcdiv($info["stat"]["net.out.errors"],$stepcount);
				$info["stat"]["net.out.dropped"]  =  bcdiv($info["stat"]["net.out.dropped"],$stepcount);
			}

			//add to result
			$nowlist[$id]=$info;
		}
		return $nowlist;
	}else{
		return false;
	}
}

$containerList = getAliveContainerList("10.10.10.201","3375");
var_dump($containerList);
