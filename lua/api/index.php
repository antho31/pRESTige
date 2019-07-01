<?php

/**
 * (c) 2019 Om Talsania
 * MIT License
 */

//error_reporting(E_ALL);
error_reporting(0);

set_time_limit(600);

$echolog[] = "";

define("REL_PATH", "../.."); //prod
//define("REL_PATH", "../../../.."); //dev

include('../index-auth.php');
$auth_val = authenticate('../');

$auth = !($auth_val) ? false : true;
define("ADMIN_MODE", $auth); //set to true to allow unsafe operations, set back to false when finished


define("LUA_OUT", "logs");
define("LUA_PID", "lua.pid.config");

$lua_ver = !empty($_POST["version"]) ? $_POST["version"] : ( !empty($_REQUEST["version"]) ? $_REQUEST["version"] : "0.9.8" );

define("LUA_VER", $lua_ver);

$lua_os = "Linux";
$lua_arch = "x86_64";

switch (PHP_OS) {
	case 'Win':
		$lua_os = "Windows";
		$lua_arch = "x86";
		break;
	case 'Darwin':
		$lua_os = "Darwin";
		$lua_arch = "x86_64";
		break;
	case 'Linux':
		$lua_os = "Linux";
		$lua_arch = "x86_64";
		break;
	default:
		$lua_os = "Linux";
		$lua_arch = "x86_64";
		break;
}

define("LUA_OS", $lua_os);

//define("LUA_ARCH", "x" . substr(php_uname("m"), -2)); //x86 or x64
define("LUA_ARCH", $lua_arch); //x86 or x64

$lua_file_in_url = "LuaDist-batteries-" . LUA_VER . "-" . LUA_OS . "-" . LUA_ARCH . ".zip";
$lua_file = "Binaries-" . $lua_file_in_url;
define("LUA_FILE_IN_URL", $lua_file_in_url);
define("LUA_FILE", $lua_file);

//$url = "https://github.com/LuaDist/Binaries/archive/LuaDist-batteries-0.9.8-Linux-x86_64.zip";
define("LUA_URL", "https://github.com/LuaDist/Binaries/archive/" .LUA_FILE_IN_URL);

define("LUA_DIR", __DIR__."/../lua");

$lua_host = !empty($_POST["host"]) ? $_POST["host"] : ( !empty($_REQUEST["host"]) ? $_REQUEST["host"] :  "localhost");
$lua_port = (int)(!empty($_POST["port"]) ? $_POST["port"] : ( !empty($_REQUEST["port"]) ? $_REQUEST["port"] :  "49999"));

define("LUA_HOST", $lua_host);
define("LUA_PORT", $lua_port);



function lua_install() {
	global $echolog;
	if(file_exists(LUA_DIR)) {
		lua_error(405);
		$echolog[] = "Lua is already installed.";
		return;
	}

	if(!file_exists(__DIR__.'/'.LUA_FILE)) {		
		$echolog[] = "Downloading Lua from " . LUA_URL . ":";

		//CURL
		
		$fp = fopen(LUA_FILE, "w");
		flock($fp, LOCK_EX);
		$curl = curl_init(LUA_URL);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_FILE, $fp);

		$resp = curl_exec($curl);
		curl_close($curl);
		flock($fp, LOCK_UN);
		fclose($fp);
		$echolog[] = $resp === true ? "Done." : "Failed. Error: curl_error($curl)";
		

		/*
		passthru("curl -O -L " . LUA_URL,$resp);
		*/

		if($resp === 0){
		} else {
			if(file_exists(__DIR__.'/'.LUA_FILE)){
				//unlink(__DIR__.'/'.LUA_FILE);
			}
		}
		
		
	
	}
	$echolog[] = "Installing Lua:";
	
	if(file_exists(__DIR__ . "/lua")){
	} else {
		exec("mkdir lua", $out0, $ret0);
	}

	exec("tar -xzvf " . LUA_FILE . " -C lua 2>&1", $out1,$ret1);
	if($ret1 === 0){
		$echolog[] = $out1;
	} else {
		$echolog[] = "Could not complete extracting the bundle.";
	}
	$extracted_dir = "/lua/Bina*";
	$cmd2 = "mv " . __DIR__ . $extracted_dir  . " " . LUA_DIR;	
	exec($cmd2, $out2, $ret2);
	if($ret2 === 0){
		exec("touch " . LUA_PID, $out3, $ret);		
		$echolog[] = $ret === 0 ? $out2 : "Failed. Error: $ret. Try putting lua folder via (S)FTP, so that " . __DIR__ . "/lua/bin/lua exists.";
	} else {
		$echolog[] = "Could not move the bundle to desired location." . "Failed. Error: $ret. Try putting lua folder via (S)FTP, so that " . __DIR__ . "/lua/bin/lua exists.";
	}

	$cmd4 = "cd ../lua/bin && curl -L https://github.com/luvit/lit/raw/master/get-lit.sh | sh";
	exec($cmd4, $out4, $ret4);
	if($ret4 === 0){
		$echolog[] = $out4;
	} else {
		$echolog[] = "Could not install luarocks. Please use the web terminal, go to lua/bin/ and execute url -L https://github.com/luvit/lit/raw/master/get-lit.sh | sh";
	}
	
//passthru("rm -f " . LUA_FILE, $ret);
	

}

function lua_uninstall() {
	global $echolog;	
	if(!file_exists(LUA_DIR)) {
		lua_error(503);
		$echolog[] = "Lua is not yet installed. Please go to Administration panel to install it.";
		return;
	}
	$echolog[] = "Unnstalling Lua:";
	exec("rm -rfv " . LUA_DIR . " " . LUA_PID . "", $out1, $ret);
	$echolog[] = $out1;
	exec("rm -rfv lua_modules", $out2, $ret);
	$echolog[] = $out2;	
	exec("rm -rfv .luarocks", $out3, $ret);
	$echolog[] = $out3;	
	exec("rm -rfv ". LUA_OUT ."", $out4, $ret);
	$echolog[] = $out4;	
	$echolog[] = $ret === 0 ? "Done." : "Failed. Error: $ret";
}

function lua_start($file) {
	global $echolog;	
	if(!file_exists(LUA_DIR)) {
		lua_error(503);
		$echolog[] = "Lua is not yet installed. Please go to Administration panel to install it.";
		return;
	}
	$lua_pid = intval(file_get_contents(LUA_PID));
	if($lua_pid > 0) {
		lua_error(405);
		$echolog[] = "Lua is already running";
		return;
	}
	$file = escapeshellarg($file);
	$start = '/workspace';
	$startlen = strlen($start);
	$pos = strpos($file, '/workspace');
	$sub = substr($file, $pos + $startlen);
	$displayFile = "{{WORKSPACE}}" . $sub;
	$echolog[] = "Starting: lua $displayFile";
	$cmd_exec = "PORT=" . LUA_PORT . " " . LUA_DIR . "/bin/lua $file >" . LUA_OUT . " 2>&1 & echo $!";
	//$echolog[] = $cmd_exec;
	$lua_pid = exec($cmd_exec);
	if($lua_pid > 0){ 
		$echolog[] = "Done. PID=$lua_pid"; 
	}
	else {
		lua_error();
		$echolog[] = "Failed.";
	}
	file_put_contents(LUA_PID, $lua_pid, LOCK_EX);
	sleep(1); //Wait for lua to spin up
	$echolog[] = file_get_contents(LUA_OUT);
}

function luvit_start($file) {
	global $echolog;	
	if(!file_exists(LUA_DIR)) {
		lua_error(503);
		$echolog[] = "Lua is not yet installed. Please go to Administration panel to install it.";
		return;
	}
	$lua_pid = intval(file_get_contents(LUA_PID));
	if($lua_pid > 0) {
		lua_error(405);
		$echolog[] = "Lua is already running";
		return;
	}
	$file = escapeshellarg($file);
	$start = '/workspace';
	$startlen = strlen($start);
	$pos = strpos($file, '/workspace');
	$sub = substr($file, $pos + $startlen);
	$displayFile = "{{WORKSPACE}}" . $sub;
	$echolog[] = "Starting: luvit $displayFile";
	$cmd_exec = "PORT=" . LUA_PORT . " " . LUA_DIR . "/bin/luvit $file >" . LUA_OUT . " 2>&1 & echo $!";
	$echolog[] = $cmd_exec;

	$lua_pid = exec($cmd_exec, $out); //" 2>&1 & echo $!");
	if($lua_pid > 0){ 
		$echolog[] = "Done. PID=$lua_pid"; 
		$echolog[] = $out; 
	}
	else {
		lua_error();
		$echolog[] = "Failed.";
		$echolog[] = $out;
	}
	file_put_contents(LUA_PID, $lua_pid, LOCK_EX);
	sleep(3); //Wait for lua to spin up
	$echolog[] = file_get_contents(LUA_OUT);
}

function lua_stop() {
	global $echolog;	
	if(!file_exists(LUA_DIR)) {
		lua_error(503);
		$echolog[] = "Lua is not yet installed. Please go to Administration panel to install it.";
		return;
	}
	$lua_pid = intval(file_get_contents(LUA_PID));
	if($lua_pid === 0) {
		lua_error(503);
		$echolog[] = "Lua is not yet running. Please go to Administration panel to start it.";
		return;
	}
	$echolog[] = "Stopping Lua with PID=$lua_pid";
	$ret = -1;
	exec("kill $lua_pid", $out, $ret);
	if($ret === 0){
		$echolog[] = $out;
	} else {
		lua_error();
		//$echolog[] = "Failed. Error: $ret";
	}
	file_put_contents(LUA_PID, '', LOCK_EX);
}

function lua_luarocks($cmd, $prefix) {
	global $echolog;	
	if(!file_exists(LUA_DIR)) {
		lua_error(403);
		$echolog[] = "Lua is not yet installed. Please go to Administration panel to install it.";
		return;
	}
	
	$prefixbase = " --prefix " . __DIR__ . "/" . REL_PATH . "/ide/workspace/";
	
	if($prefix) {
		$prefixpassed = $prefix;
		if(endsWith($prefix, ".py")){
			$exp = explode("/", $prefix);
			array_pop($exp);
			$stripped = implode("/", $exp);
			$prefixpassed = $stripped;
		}
		$prefixcmd = $prefixbase . $prefixpassed;	
	} else {
		$prefixcmd = $prefixbase . "lua";
	}
	
	$cmd = escapeshellcmd(LUA_DIR . "/bin/luarocks " /* . $prefixcmd */  . " -- $cmd");
	
	$echolog[] = "Running: $cmd";
	$ret = -1;
	exec($cmd, $out, $ret);
	if($ret === 0){
		$echolog[] = $out;
		$echolog[] = "Done";
	} else {
		lua_error();
		$echolog[] = "Failed. Error: $ret. \r\n " . json_encode($out) ." \r\n See <a href=\"luarocks-debug.log\">luarocks-debug.log</a>";
	}
	return;

}

function lua_serve($path = "") {
	
	global $echolog;	
	if(!file_exists(LUA_DIR)) {
		//lua_head();
		lua_error(503);
		$echolog[] = "Lua is not yet installed. Please go to Administration panel to install it.";
		//lua_foot();
		return;
	}
	$lua_pid = intval(file_get_contents(LUA_PID));
	if($lua_pid === 0) {
		//lua_head();
		lua_error(405);
		$echolog[] = "Lua is not yet running. Please go to Administration panel to start it.";
		//lua_foot();
		return;
	}
		
	$curl = curl_init("http://" . LUA_HOST . ":" . LUA_PORT . "/$path");
	curl_setopt($curl, CURLOPT_HEADER, 1);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $headers = array();
        foreach(getallheaders() as $key => $value) {
                $headers[] = $key . ": " . $value;
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
        if($_SERVER["REQUEST_METHOD"] === "POST") {
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($_POST));
        }
        if($_SERVER["REQUEST_METHOD"] === "PUT") {
		$putData = @file_get_contents('php://input');
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($putData));
	}
	
 	$resp = curl_exec($curl);

	if($resp === false) {
		//lua_head();
		lua_error();
		$echolog[] = "Error requesting $path: " . curl_error($curl);
		return;
		//lua_foot();
	} else {
		list($head, $body) = explode("\r\n\r\n", $resp, 2);

		$headarr = explode("\n", $head);
		foreach($headarr as $headval) {
			if($headval == "Transfer-Encoding: chunked") continue;
			header($headval);
		}
		echo $body;
	}
	 	
	curl_close($curl);
	
	
	exit();
}


function lua_status() {
	global $echolog;	
	$result = array();
	if(!file_exists(LUA_DIR)) {
		$result["installed"] = false;
		$result["installationStatus"] = "Not Installed";
	} else {
		$result["installed"] = true;
		$result["installationStatus"] = "Installed";
	}
	$lua_pid = intval(file_get_contents(LUA_PID));
	if($lua_pid > 0) {
		$result["running"] = true;
		$result["processStatus"] = "Running";
	} else {
		$result["running"] = false;
		$result["processStatus"] = "Stopped";
	}
	echo json_encode($result);
	exit();
}


function lua_head() {
	$echolog[] = '<!DOCTYPE html><html><head><title>Lua.php</title><meta charset="utf-8"><body style="font-family:Helvetica,sans-serif;"><h1>Lua.php</h1><pre>';
}

function lua_foot() {
	$echolog[] = '</pre><p><a href="https://github.com/niutech/lua.php" target="_blank">Powered by lua.php</a></p></body></html>';
}

function lua_api_head(){
	header('Content-Type: application/json');
}

function lua_error($code){
	if (empty($code)) $code = 500;
	http_response_code($code);
}

function lua_success($code){
	if (empty($code)) $code = 200;
	http_response_code($code);
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    if ($length == 0) {
        return true;
    }

    return (substr($haystack, -$length) === $needle);
}


function lua_dispatch() {
	global $echolog;	
	if(ADMIN_MODE) {
		
			
		
		
		
		

		//lua_head();
		lua_api_head();


		if($_FILES['file-0']){
			//print_r($_FILES['file-0']);
			file_put_contents($_FILES['file-0']['name'], $_FILES['file-0']);
			$echolog[] = "Successfully uploaded " . $_FILES['file-0']['name'];
			array_shift($echolog);
			echo json_encode($echolog);
			exit();
		};
			

		
		if($install = isset($_GET['install']) ? ($_GET['install']) : (isset($_POST['install']) ? ($_POST['install']) :  false)) {
			lua_install();
		} elseif($uninstall = isset($_GET['uninstall']) ? ($_GET['uninstall']) : (isset($_POST['uninstall']) ? ($_POST['uninstall']) :  false)) {
			lua_uninstall();
		} elseif($start = isset($_GET['start']) ? ($_GET['start']) : (isset($_POST['start']) ? ($_POST['start']) :  false)) {
			$serve_path = __DIR__ . '/' . REL_PATH . '/ide/workspace/' . $start;
			lua_start($serve_path);
		} elseif($start = isset($_GET['luvitstart']) ? ($_GET['luvitstart']) : (isset($_POST['luvitstart']) ? ($_POST['luvitstart']) :  false)) {
			$serve_path = __DIR__ . '/' . REL_PATH . '/ide/workspace/' . $start;
			luvit_start($serve_path);
		} elseif($stop = isset($_GET['stop']) ? ($_GET['stop']) : (isset($_POST['stop']) ? ($_POST['stop']) :  false)) {
			lua_stop();
		} elseif($luarocks = isset($_GET['luarocks']) ? ($_GET['luarocks']) : (isset($_POST['luarocks']) ? ($_POST['luarocks']) :  false)) {
			$prefix = isset($_GET['prefix']) ? ($_GET['prefix']) : (isset($_POST['prefix']) ? ($_POST['prefix']) :  false);
			lua_luarocks($luarocks, $prefix);
		} elseif($luastatus = isset($_GET['status']) ? ($_GET['status']) : (isset($_POST['status']) ? ($_POST['status']) :  false)) {
			lua_status();
		} else {
		 	$echolog[] = "You are in Admin Mode. Switch back to normal mode to serve your lua app.";
		}		
		//lua_foot();
	} else {
		lua_api_head();

		if($path = isset($_GET['path']) ? ($_GET['path']) : (isset($_POST['path']) ? ($_POST['path']) :  false)) {
			lua_serve($path);
		} else {
			lua_serve();
		}
		
	}
	array_shift($echolog);
	echo json_encode($echolog);
}

lua_dispatch();
