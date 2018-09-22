<?php

function get_current_api_path(){
	//if(!$resterController) $resterController = new ResterController();
	global $resterController;
	$currentMethod = $_SERVER['REQUEST_METHOD'];
	$currentRoute = $resterController->getCurrentRoute();
	$currentPath = $resterController->getCurrentPath()[0];
	$currentApi = $currentMethod . ' ' . $currentRoute;
	if($currentPath) $currentApi = $currentApi . '/' . $currentPath;
	return $currentApi;
}

function api_get_current_route(){
	return get_current_api_path();
}

//$exclude = array("GET hello/world", "POST users/login");
function check_simple_auth($exclude)
{
		if($exclude){
			if(in_array(get_current_api_path(), $exclude)){
				return true;
			}
		}
		//if(!$resterController) $resterController = new ResterController();
		global $resterController;
		$headers = getallheaders();
		//$allowed_auth_headers = array("api_key", "API_KEY", "Api_Key", "Api_key", "api-key", "API-KEY", "Api-Key", "Api-key");
		$allowed_auth_headers = array("api_key", "api-key");
		$auth_header = $headers['api_key'];
		if(!$auth_header) $auth_header = $_REQUEST['api_key'];
		if(!$auth_header){
			foreach($headers as $key=>$val){
				if(in_array(strtolower($key), $allowed_auth_headers)) $auth_header = $val;
			}
		}
		if($auth_header){
			$value = $resterController->query("select * from users where token='$auth_header' and datediff(now(), lease) = 0");
			if($value){
				return $value;
			}
			else
			{
				$resterController->showErrorWithMessage(401,"Unauthorized");
			}
		}
		else{
			$resterController->showErrorWithMessage(401,"Unauthorized");
		}
}


//$exclude = array("GET ", GET hello/world", "POST users/login");
function check_simple_saas($exclude, $check_request_authenticity = false)
{
	global $resterController;
	
	if((isset($exclude) && in_array(get_current_api_path(), $exclude)) || strpos(get_current_api_path(), "api-doc") > -1 || strpos(get_current_api_path(), "files") > -1){
		return true;
	}
	else{
		if(strpos(get_current_api_path(), "GET") > -1){
			if(!isset($_REQUEST['secret'])){
				$resterController->showErrorWithMessage(403, 'Forbidden. Your secret is safe!');
			}
		}
		if(strpos(get_current_api_path(), "POST") > -1){
		    $body = $resterController->getPostData();
			if(empty($body['secret'])){
				$resterController->showErrorWithMessage(403, 'Forbidden. Your secret is safe!');
			}
		}
		if($check_request_authenticity) check_request_authenticity();
		
	}

}


function check_request_authenticity(){
	$api = new ResterController();

	$headers = getallheaders();
	$api_key = '';
	foreach($headers as $k => $v){
		if(in_array(strtolower($k), array('api-key','api_key'))){
			$api_key = $v;
		}
	}
	
	$api_key = empty($api_key) ? (empty($_REQUEST['api-key']) ? $_REQUEST['api_key'] : $_REQUEST['api-key']) : $api_key;
		
	$request_body = $api->getRequestBody();
	$secret = empty($request_body['secret']) ? $_REQUEST['secret'] : $request_body['secret'];

	
	if(!empty($secret) && !empty($api_key))
	{
		$val = $api->query("select count(*) as records from users where token='$api_key' and secret='$secret'");
		if (!((count($val) > 0) && $val[0]["records"] > 0)){
			$api->showErrorWithMessage(403, 'Forbidden');
		}
	}
}

function check_response_authenticity($result){
	$api = new ResterController();

	$request_body = $api->getRequestBody();
	$secret = empty($request_body['secret']) ? $_REQUEST['secret'] : $request_body['secret'];


	if(!empty($secret) && !empty($result))
	{
		if (!($secret == $result['secret'])){
			$api->showErrorWithMessage(403, 'Forbidden');
		}
	}
}

function check_organization_is_active($secret){
	global $resterController;

	try {
		if(!empty($secret)){
			$val = $resterController->query("select count(*) as records from organizations where org_secret='$secret' and is_active=1");
			if (!((count($val) > 0) && $val[0]["records"] > 0)){
				$resterController->showErrorWithMessage(401, 'User belongs to an inactive organization!');
			}
			$val = $resterController->query("select count(*) as records from (select *, if(curdate() > validity and license not in ('basic', 'super'), 'expired', 'valid') as license_status from organizations where org_secret='$secret') as o where license_status = 'valid'");
			if (!((count($val) > 0) && $val[0]["records"] > 0)){
				$resterController->showErrorWithMessage(401, 'Organization license is expired! Please renew to continue using the system.');
			}
		}
	} catch (Exception $ex){
		
	}
}

/**
* Sample custom login command
*/
//Create the command
// $loginCommand = new RouteCommand("POST", "users", "login", function($params = NULL) {
// 	global $resterController;
// 	$filter["login"]=$params["login"];
// 	$filter["password"]=md5($params["password"]);
// 	$result = $resterController->getObjectsFromRouteName("users", $filter);
// 	$resterController->showResult($result);
// }, array("login", "password"), "Method to login users");

$loginFunction = function($params = NULL) {
	
	$api = new ResterController();

	//Check if the users table exists
	try{
		$tableExists = $api->query('select 1 from users');
	}
	catch(Exception $e){
		$api->showErrorWithMessage(503, "Can't find table named 'users'. Please check the documentation for more info.");
	}		
	
	$email = $params["email"];
	$username = $params["username"];
	$password = $params["password"];
	
	
	//Need to pass username/email and password.
	if($email == null && $username == null)
	{
		$errorResult = array('error' => array('code' => 422, 'status' => 'Required - username/email'));
		$api->showResult($errorResult);
	}
	if($password == null)
	{
		$errorResult = array('error' => array('code' => 422, 'status' => 'Required - password'));
		$api->showResult($errorResult);
	}
		
	//Prefer login through e-mail. Alternately accept username.
	if($email != null) {
		$filter["email"]=$email;
	}
	else {
		$filter["username"]=$username;
	}
	
	$user_exists = $api->getObjectsFromRouteName("users", $filter);
	
	$filter["password"]=md5($password);
	
	/*Match details with database. There needs to be a table with the following fields
		users {
			id (integer): id field integer,
			email (string): email field string,
			username (string): username field string,
			password (string): password field string,
			token (string): token field string,
			lease (string): lease field string(timestamp),
			is_active (integer): is_active field integer
		}
		where email and username should be marked as UNIQUE index and id as PRIMARY index.
		
		DROP TABLE IF EXISTS `users`;
		CREATE TABLE `users` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `email` varchar(100) NOT NULL,
		  `username` varchar(50) NOT NULL,
		  `password` varchar(100) NOT NULL,
		  `token` varchar(50) NOT NULL,
		  `lease` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  `is_active` tinyint(1) NOT NULL DEFAULT '1',
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `email` (`email`)
		);

	*/
	$result = $api->getObjectsFromRouteName("users", $filter);
	
	
	if($result == null){
		if(!$user_exists){
			$api->showErrorWithMessage(404, "User does not exist!");
		}
		//$errorResult = array('error' => array('code' => 401, 'status' => 'Unauthorized'));
		//$api->showResult($errorResult);
		$api->showErrorWithMessage(401, "Invalid email/username or password!");
	}
	else{
		$is_active = $result[0]['is_active'];
		if(!$is_active){
			$api->showErrorWithMessage(401, "Inactive user!");
		}
		$new_token = uuid();
		$update_id = $result[0]['id'];
		$update_query = "update users set token = '$new_token', lease=now() where id = '$update_id' and ifnull(datediff(now(), lease), 1) > 0";
		$updated = $api->query($update_query);
		
		$result = $api->getObjectsFromRouteName("users", $filter);
		foreach ($result as &$r) {
			$r['password'] = 'Not visible for security reasons';
		}
		
		check_organization_is_active($result[0]["secret"]);

		$api->showResult($result);
	}

};

$loginCommand = new RouteCommand("POST", "users", "login", $loginFunction, array("email", "password"), "Method to login users");


$setPasswordFunction = function($params = NULL) {
	$api = new ResterController();
	
	$filter['email'] = $params['admin_email'];
	$filter['password'] = md5($params['admin_password']);
	
	$result = $api->getObjectsFromRouteName("users", $filter);

	if(count($result) > 0){

		if(empty(array_search(($result[0]["role"]), array("admin", "superadmin")) > -1)){
			$api->showErrorWithMessage(403, 'Forbidden');
		}
		
		$user_filter['email'] = $params['email'];
		
		$user = $api->getObjectsFromRouteName("users", $user_filter);

		$user[0]['password'] = md5($params['password']);

		$result = $api->updateObjectFromRoute("users", $user[0]['id'], $user[0]);

		if(!empty($result)){ 
		
			foreach ($result as &$r) {
				$r['password'] = 'Not visible for security reasons';
			}
	
			$api->showResult($result);
		} else {
			$api->showErrorWithMessage(403, 'Forbidden');
		}
		
	} else{
		$api->showErrorWithMessage(403, 'Forbidden');
	}
	
};

$setPasswordCommand = new RouteCommand("POST", "users", "set-password", $setPasswordFunction, array("email", "password", "admin_email", "admin_password"), "Method to set user password by admin");


$changePasswordFunction = function($params = NULL) {
	$api = new ResterController();
	
	$filter['email'] = $params['email'];
	$filter['password'] = md5($params['password']);
	
	$result = $api->getObjectsFromRouteName("users", $filter);

	if(count($result) > 0){

		$result[0]['password'] = md5($params['new_password']);
		$result = $api->updateObjectFromRoute("users", $result[0]['id'], $result[0]);

		if(!empty($result)){ 
		
			foreach ($result as &$r) {
				$r['password'] = 'Not visible for security reasons';
			}
	
			$api->showResult($result);
		} else {
			$api->showErrorWithMessage(403, 'Forbidden');
		}
		
	} else{
		$api->showErrorWithMessage(403, 'Forbidden');
	}
	
};

$changePasswordCommand = new RouteCommand("POST", "users", "change-password", $changePasswordFunction, array("email", "password", "new_password"), "Method to change password");

$forgotPasswordFunction = function($params = NULL) {
	$api = new ResterController();
	
	$filter['email'] = $params['email'];

	$result = $api->getObjectsFromRouteName("users", $filter);

	if(count($result) > 0){

		if(!$result[0]['is_active']){
			$api->showErrorWithMessage(405, 'Inactive user!');
		}
		
		$new_password = "pRESTige";
		$result[0]['password'] = md5($new_password);
		
		$result = $api->updateObjectFromRoute("users", $result[0]['id'], $result[0]);
		
		if(!empty($result)){ 
		
			foreach ($result as &$r) {
				$r['password'] = 'Not visible for security reasons';
			}
			
			if(function_exists('on_forgot_password')){
				on_forgot_password($result[0]['email'], $new_password);
			}
			
	
			$api->showResult($result);
		} else {
			$api->showErrorWithMessage(405, 'Invalid operation!');
		}
		

	} else{
		$api->showErrorWithMessage(404, 'User does not exist!');
	}
	
};

$forgotPasswordCommand = new RouteCommand("POST", "users", "forgot-password", $forgotPasswordFunction, array("email"), "Method to recover forgot password");


//Add the command to controller
//$resterController->addRouteCommand($loginCommand);
if(DEFAULT_LOGIN_API == true){
	$resterController->addRouteCommand($loginCommand);
	$resterController->addRouteCommand($setPasswordCommand);
	$resterController->addRouteCommand($changePasswordCommand);
	$resterController->addRouteCommand($forgotPasswordCommand);
	check_simple_auth(array("POST users/login", "GET hello/world"));
}


/**
* Sample organization activate command
*/
$activateFunction = function($params = NULL) {
	
	$api = new ResterController();

	//Check if the users table exists
	try{
		$tableExists = $api->query('select 1 from organizations');
	}
	catch(Exception $e){
		$api->showErrorWithMessage(503, "Can't find table named 'organizations'. Please check the documentation for more info.");
	}		
	
	
	$id = $params["id"];
	$secret = $params["secret"];

	$filter['id'] = $id;
	$filter['secret'] = $secret;
	// //Need to pass username/email and password.
	// if($email == null && $username == null)
	// {
	// 	$errorResult = array('error' => array('code' => 422, 'status' => 'Required - username/email'));
	// 	$api->showResult($errorResult);
	// }
	// if($password == null)
	// {
	// 	$errorResult = array('error' => array('code' => 422, 'status' => 'Required - password'));
	// 	$api->showResult($errorResult);
	// }
		
	// //Prefer login through e-mail. Alternately accept username.
	// if($email != null) {
	// 	$filter["email"]=$email;
	// }
	// else {
	// 	$filter["username"]=$username;
	// }
	// $filter["password"]=md5($password);
	
	/*Match details with database. There needs to be a table with the following fields
		users {
			id (integer): id field integer,
			email (string): email field string,
			username (string): username field string,
			password (string): password field string (md5 encrypted),
			token (string): token field string,
			lease (datetime): lease field datetime,
			role (string, optional): role field string ('user', 'admin'),
			is_active (integer): is_active field integer,
			secret (string): secret field string
		}
		where email and username should be marked as UNIQUE index and id as PRIMARY index.
		
		organizations {
			id (integer): id field integer,
			name (string): name field string,
			email (string): email field string,
			license (string): license field string,
			validity (datetime): validity field datetime,
			is_active (integer): is_active field integer,
			org_secret (string): org_secret field string,
			secret (string, optional): secret field string
		}
		
		DROP TABLE IF EXISTS `users`;
		CREATE TABLE `users` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `email` varchar(100) NOT NULL,
		  `username` varchar(50) NOT NULL,
		  `password` varchar(100) NOT NULL,
		  `token` varchar(50) NOT NULL,
		  `lease` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		  `role` varchar(50) DEFAULT 'user',
		  `is_active` tinyint(1) NOT NULL DEFAULT '1',  		  
		  `secret` varchar(50) NOT NULL DEFAULT '206b2dbe-ecc9-490b-b81b-83767288bc5e',
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `email` (`email`)
		);

		-- SQL Script for creating organizations table that can be used to associate secret key with each unique organization
		DROP TABLE IF EXISTS `organizations`;
		CREATE TABLE `organizations` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `name` varchar(255) NOT NULL,
		  `email` varchar(100) NOT NULL,		  
		  `license` varchar(15) NOT NULL DEFAULT 'basic',
		  `validity` datetime NOT NULL,
		  `is_active` tinyint(1) NOT NULL DEFAULT '0',  
		  `org_secret` varchar(50) NOT NULL,
		  `secret` varchar(50) NOT NULL DEFAULT '206b2dbe-ecc9-490b-b81b-83767288bc5e',
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `org_secret` (`org_secret`)
		) ENGINE=InnoDB DEFAULT CHARSET=latin1;

	*/
	$result = $api->getObjectsFromRouteName("organizations", $filter);
	
	
	if($result == null){
		$errorResult = array('error' => array('code' => 404, 'status' => 'Not found'));
		$api->showResult($errorResult);
	}
	else{
		$update_id = $result[0]['id'];
		$license = empty($params['license']) ? $result[0]['license'] : $params['license'];
		$validity = empty($params['validity']) ? $result[0]['validity'] : $params['validity'];
		$update_query = "update organizations set is_active = '1', license = '$license', validity = '$validity' where id = '$update_id'";
		$updated = $api->query($update_query);

		// $select_query = "select org_secret from organizations where id = '$update_id'";
		// $seleted = $api->query($select_query);
		$org_secret = $result[0]['org_secret'];
		$email = $result[0]['email'];

		$select_query = "select * from users where secret = '$org_secret' and email = '$email'";
		$seleted = $api->query($select_query);
		$user_id = $seleted[0]['id'];
		
		
		if($user_id){
			$activation_query = "update users set is_active = '1', role = 'admin' where id = '$user_id'";
			$activated = $api->query($activation_query);
		} else {
			$activation_query = "INSERT INTO `users` (`email`, `username`, `password`, `token`, `lease`, `role`, `secret`, `is_active`) VALUES ('$email',	'$email',	'21232f297a57a5a743894a0e4a801fc3',	'1',	'0000-00-00 00:00:00',	'admin', '$org_secret', 1)";
			$user_id = $api->query($activation_query);
		}
		
		$resultFilter = array("id" => $user_id);
		$result = $api->getObjectsFromRouteName("users", $resultFilter);
		foreach ($result as &$r) {
			$r['password'] = 'Not visible for security reasons';
		}
		
		$organization = $api->getObjectsFromRouteName("organizations", $filter);
		
		try{
			if(function_exists('on_organization_activated')){
				on_organization_activated($organization, $result);
			}
		} catch (Exception $ex){
			
		}

		$api->showResult($result);
	}

};

$activateCommand = new RouteCommand("POST", "organizations", "activate", $activateFunction, array("org_secret"), "Method to activate an organization.");

if(DEFAULT_SAAS_MODE == true){
	$resterController->addRouteCommand($activateCommand);
	check_simple_saas(array("GET ", "POST users/login", "GET hello/world"));
}


function enable_simple_auth($exclude){
	if(!DEFAULT_LOGIN_API){
		global $resterController, $loginCommand, $setPasswordCommand, $changePasswordCommand, $forgotPasswordCommand;
		$resterController->addRouteCommand($loginCommand);
		$resterController->addRouteCommand($setPasswordCommand);
		$resterController->addRouteCommand($changePasswordCommand);
		$resterController->addRouteCommand($forgotPasswordCommand);
		check_simple_auth(array_merge(array("POST users/login", "GET hello/world"), $exclude));
	}
}

function enable_simple_saas($exclude, $check_request_authenticity  = false){
	if(!DEFAULT_SAAS_MODE){
		global $resterController, $activateCommand;
		$resterController->addRouteCommand($activateCommand);
		check_simple_saas(array_merge(array("GET ", "POST users/login", "GET hello/world"), $exclude), $check_request_authenticity);
	}
}


//Test Login using GET
//$loginGetCommand = new RouteCommand("GET", "users", "login", $loginFunction, array("email", "password"), "Method to login users");
//$resterController->addRouteCommand($loginGetCommand);

//Disable oauth authentication for certain routes
$resterController->addPublicMethod("POST", "users/login");

//Add file processor. parameter db_name, db_field. will update the db field based on relative path
/*
	DROP TABLE IF EXISTS `files`;
	CREATE TABLE `files` (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `file` varchar(512) DEFAULT NULL,
	  PRIMARY KEY (`id`)
	);
*/
//$resterController->addFileProcessor("files", "file");
if(DEFAULT_FILE_API == true){
	$resterController->addFileProcessor("files", "file");
}

function enable_files_api(){
	if(!DEFAULT_FILE_API){
		global $resterController;
		$resterController->addFileProcessor("files", "file");
	}
}

//Custom API
//$helloWorldApi = new RouteCommand("GET", "hello", "world", function($params=null){
//	$api = new ResterController();
//	$value = $api->query("select 'world' as 'hello'"); //you can do any type of MySQL queries here.
//	$api->showResult($value);
//}, array(), "Hello World Api");
//$resterController->addRouteCommand($helloWorldApi);

//Sample Custom API. 
// $prestige->addRouteCommand(new RouteCommand("GET", "hello", "world", function($params=null){
// 	global $prestige;
// 	$value = $prestige->query("select 'world' as 'hello'"); //you can do any type of MySQL queries here.
// 	$prestige->showResult($value);
// }, array(), "Hello World Api"));



//Include APIs created using IDE
//if(file_exists(__DIR__."/../ide/workspace/api/index.php")){
//        include(__DIR__."/../ide/workspace/api/index.php");
//}

//Include All APIs created using IDE (Including those in sub-folders)
function getAllSubDirectories( $directory, $directory_seperator )
{
	$dirs = array_map( function($item)use($directory_seperator){ return $item . $directory_seperator;}, array_filter( glob( $directory . '*' ), 'is_dir') );

	foreach( $dirs AS $dir )
	{
		$dirs = array_merge( $dirs, getAllSubDirectories( $dir, $directory_seperator ) );
	}

	return $dirs;
}

$apiDirectory = __DIR__.'/../../ide/workspace/api/';

$subDirectories = getAllSubDirectories($apiDirectory,'/');

array_push($subDirectories, $apiDirectory);

foreach($subDirectories as &$subDir){
	$path = $subDir;
	
	$files = array_diff(scandir($path), array('.', '..'));
	foreach ($files as &$file) {
		$filePath = $path.$file;
		if(substr($filePath, -4) == ".php"){
			if(file_exists($filePath)){
			        include($filePath);
			}
		}
	}
}

?>
