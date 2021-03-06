<?php

require_once('config.php');
require_once(__DIR__.'/ResterUtils.php');
require_once(__DIR__.'/UUID.php');
require_once(__DIR__.'/model/RouteFileProcessor.php');
require_once('OAuthServer.php');
require_once('OAuthStore.php');


class ResterController {

	var $routes = array();
	
	var $customRoutes = array();
	
	var $custom_routes = array();
	
	var $stored_procedures = array();
	
	var $nav_routes = array();
	
	var $dbController;
	
	var $requestProcessors = array();
	
	var $NESTED_COUNTER = 1;
	
	var $requestBody = null;

	
	/**
	* Indexed array containing the methods don't checked by OAuth
	*/
	var $publicMethods;
	
	function ResterController() {
		$this->dbController = new DBController();
		$this->checkConnectionStatus();
		
		//Internal processors
		
		$this->addRequestProcessor("OPTIONS", function($routeName = NULL, $routePath = NULL, $parameters = NULL) {
			if(isset($routeName) && isset($routePath)) {
				$this->doResponse(SwaggerHelper::getDocFromRoute($this->getAvailableRoutes()[$routePath[0]], $this->getAvailableRoutes()));
			}
			$this->doResponse(NULL);
		});
		
		$this->addRequestProcessor("HEAD", function($routeName = NULL, $routePath = NULL, $parameters = NULL) {
			$this->showResult("");
		});
		
		/**
		* This is the main GET processor
		* If the request does not have a route, shows the doc for swagger
		* If we have a route and no operation or parameters, show the doc for swagger (route specific)
		* Else we process the parameters, checks if we have a command or an ID to return the results 
		*/
		$this->addRequestProcessor("GET", function($routeName = NULL, $routePath = NULL, $parameters = NULL) {
			
			//If we ask for the root, give the docs
			if($routeName == NULL) {
				$this->showRoutes();
			}
			
			if(isset($routeName) && $routeName == "api-doc" && isset($routePath)) {
				ResterUtils::Log("Returning apidoc");
				$this->doResponse(SwaggerHelper::getDocFromRoute($this->getAvailableRoutes()[$routePath[0]], $this->getAvailableRoutes()));
			}

			if(isset($routeName) && $routeName == "api-doc-proc" && isset($routePath)) {
				ResterUtils::Log("Returning procedures apidoc");
				$this->doResponse(SwaggerHelper::getDocFromRoute($this->getAvailableProcRoutes()[$routePath[0]], $this->getAvailableCustomRoutes(), true));
			}

			
			if(isset($routeName) && $routeName == "api-doc-custom" && isset($routePath)) {
				ResterUtils::Log("Returning custom apidoc");
				$this->doResponse(SwaggerHelper::getDocFromRoute($this->getAvailableCustomRoutes()[$routePath[0]], $this->getAvailableCustomRoutes(), true));
			}

			
			$this->checkRouteExists($routeName);
			
			
		
			if(count($routePath) >= 1) {
				$command = $routePath[0];
				
				$this->checkRouteCommandExists($routeName, $command, "GET");
				
				if(isset($this->customRoutes["GET"][$routeName][$command])) {
					$callback = $this->customRoutes["GET"][$routeName][$command];
					call_user_func($callback, $parameters);
				} else {
					if(count($routePath) == 2){
						$route = $this->getRoute($routePath[1]);
						if(!empty($route)){
							
							foreach($route->routeFields as $k=>$v){
								if($v->isRelation){
									if($v->relation->destinationRoute == $routeName){
										
										$relations[] = $v->relation->field;
									}
								}
								
							}
							
							$rel = array_shift($relations);
							
							$callback = $this->requestProcessors["GET"][0];
							if(empty($parameters)) $parameters = array();
							$parameters[$rel] = $routePath[0];
							call_user_func($callback, $routePath[1], null, $parameters);
						} else {
							$this->showError(404, "Requested route does not exist.");
						}
						
					} else {
						$result = array_shift($this->getObjectByID($routeName, $command));
						if(function_exists('check_response_authenticity')) check_response_authenticity($result);
					}
					$this->showResult($result);
				}								
			} else {
				if(isset($parameters)){
					$result = $this->getObjectsFromRoute($this->routes[$routeName], $parameters);
				} else
					$result = $this->getObjectsFromRoute($this->routes[$routeName]);
				//show result forcing array result
				$this->showResult($result, 200, true);
			}
		});
		
		$this->addRequestProcessor("POST", function($routeName = NULL, $routePath = NULL, $parameters = NULL) {

			if(!isset($routeName)) {
				$this->showError(400, "Invalid route name supplied.");
			}
			
			$body = $this->getPostData();

			//Register event hook
			try{
				$func = 'before_post_' . $routeName;
				if(function_exists($func)){
					$func($body);
				}
			} catch (Exception $ex){
				
			}
			
			//Check for command
			if(count($routePath) == 1) {
				$command = $routePath[0];

				$this->checkRouteCommandExists($routeName, $command, "POST");

				if(isset($this->customRoutes["POST"][$routeName][$command])) {
					ResterUtils::Log(">> Executing custom command <".$command.">");
					$callback = $this->customRoutes["POST"][$routeName][$command];
					call_user_func($callback, $body);
					return;
				} else { 
					
					if(LEGACY_MODE){
						$methodOverride = strtoupper($routePath[0]);
						if(in_array($methodOverride, array("GET", "UPDATE", "DELETE"))){
							if($methodOverride == "UPDATE") $methodOverride = "PUT";
							$callbackParameters[0] = $routeName;
							$callbackOverride = $this->requestProcessors[$methodOverride];
							switch ($methodOverride) {
								case 'GET':
									$callbackParameters[1] = null;
									$callbackParameters[2] = $parameters;
									break;
								case 'PUT':
									$callbackParameters[1] = null;
									break;
								case 'DELETE':
									$route = $this->getRoute($routeName);
								    $key = $route->primaryKey->fieldName;
								    //if(empty($key)) $key = "id";
									if(!empty($parameters) && !empty($parameters[$key])){
										$callbackParameters[1] = array($parameters[$key]);
									} else {
										$callbackParameters[1] = null;
										//$this->showError(422, "Missing parameter: $key");
									}
									break;
							}

							call_user_func_array($callbackOverride[0], $callbackParameters);						
							exit();
						}
					}
					

					//tenemos un id; hacemos un update
					ResterUtils::Log(">> Update object from ID");
					$this->processFiles($this->getAvailableRoutes()[$routeName], $routePath[0]);
					$result = $this->updateObjectFromRoute($routeName, $routePath[0], $_POST);

					//Register event hook
					try{
						$func = 'on_post_' . $routeName;
						if(function_exists($func)){
							$func($result);
						}
					} catch (Exception $ex){
						
					}
					
					
					$this->showResult($result);
				}
			} else if(count($routePath) == 2){
					if(LEGACY_MODE){
						$methodOverride = strtoupper($routePath[0]);
						if(in_array($methodOverride, array("GET", "UPDATE", "DELETE"))){
							if($methodOverride == "UPDATE") $methodOverride = "PUT";
							$callbackParameters[0] = $routeName;
							$callbackOverride = $this->requestProcessors[$methodOverride];
							switch ($methodOverride) {
								case 'GET':
									$callbackParameters[1] = null;
									$callbackParameters[2] = array("id" => $routePath[1]);
									break;
								case 'PUT':
									$callbackParameters[1] = array($routePath[1]);
									break;
								case 'DELETE':
									$callbackParameters[1] = array($routePath[1]);
									break;
							}

							call_user_func_array($callbackOverride[0], $callbackParameters);						
							exit();
						}
					}
									
			}
			
			
			if($body == NULL || empty($body)) {
				if(count($_FILES) > 0){
					//not postbody and no post data... we create something...
					ResterUtils::Log(">> CREATING BAREBONE 8======8");
					$barebone = array();
					$result = $this->insertObject($routeName, $barebone); //give all the post data	
				} else {
					$this->showError(400, "The request body is empty.");
				}
			} else {
				//Create object from postbody
				ResterUtils::Log(">> CREATING OBJECT FROM POSTBODY: *CREATE* - ".$routeName);
				//ResterUtils::Dump($body);
					
				$route = $this->getAvailableRoutes()[$routeName];
					
				$existing = $this->getObjectByID($routeName, $body[$route->primaryKey->fieldName]);
					
				if($existing) { //we got an id, let's update the values
					$result = $this->updateObjectFromRoute($routeName, $body[$route->primaryKey->fieldName], $body);
				} else {
					if(is_array($body) && count($body) > 0 && is_array($body[0])){
						for ($i = 0; $i < count($body); $i++) {
							 $b = $body[$i];
							 $result[] = $this->insertObject($routeName, $b);
						}
					} else {
						$result = $this->insertObject($routeName, $body);	
					}
					
				}
			}
			
			//Register event hook
			try{
				$func = 'on_post_' . $routeName;
				if(function_exists($func)){
					$func($result);
				}
			} catch (Exception $ex){
				
			}

				
			$this->showResult($result, 201);
			
		});
		
		$this->addRequestProcessor("DELETE", function($routeName, $routePath) {
			if(!isset($routeName)) {
				$this->showError(400, "Invalid route name supplied.");
			}
			
			
	
			if(!isset($routePath) || count($routePath) < 1) {
				//$this->showError(404);
				$route = $this->getRoute($routeName);
			    $key = $route->primaryKey->fieldName;
			    $idstr = $_REQUEST[$key];
			    if(empty($idstr)){
			        $this->showError(422, "Missing parameter: $key");    
			    }
			    $ids = json_decode($idstr);

			    if(!is_array($ids)){
			        $ids = array($ids);
			    }
				
			} else {
			    $ids = array($routePath[0]);
			}
			
			if(count($ids) == 1){
				$id = $ids[0];
			} else {
				$id = $ids;
			}
			
			//Register event hook
			try{
				$func = 'before_delete_' . $routeName;
				if(function_exists($func)){
					$func($id);
				}
			} catch (Exception $ex){
				
			}
			
			for ($i = 0; $i < count($ids); $i++) {
				 $deleted = $this->deleteObjectFromRoute($routeName, $ids[$i]);
				 if($deleted > 1) $success[]  = $deleted;
				 else $failures[] = $ids[$i];
			}
			
			$result = array("deleted" => empty($success) ? "None" : $success, "failed_to_delete" => empty($failures) ? "None" : $failures);
			
			//Register event hook
			try{
				$func = 'on_delete_' . $routeName;
				if(function_exists($func)){
					$func($result);
				}
			} catch (Exception $ex){
				
			}
			
			$passed = !empty($success)  && count($success) > 0;
			$failed = !empty($failures)  && count($failures) > 0;
			
			if($failed && !$passed) {
				$this->showError(404, "Could not find the object you are trying to delete.", $result);
			} else if ($failed && $passed){
				$this->showError(409, "Partially deleted!", $result);
			} else {
				$this->showResult(ApiResponse::successResponse($result));
			}
		
		});
		
		$this->addRequestProcessor("PUT", function($routeName, $routePath) {
			
			ResterUtils::Log("PROCESSING PUT");
			if(!isset($routeName)) {
				$this->showError(400, "Invalid route name supplied.");
			}

			//$input = file_get_contents('php://input');
			$input = $this->getRequestBody();
			
			if(LEGACY_MODE){
				$input = $this->getPostData();
			}
			
			if(empty($input)) {
				ResterUtils::Log("Empty PUT request");
				$this->showError(400, "The request body is empty.");
			}

			if(!isset($routePath) || count($routePath) < 1) { //no id in URL, we expect json body
				//$putData = json_decode($input, true);
				//$putData = $input;
				
				if(is_string($input)) parse_str($input, $putData);
				else $putData = $input;
				
				
				//Register event hook
				try{
					$func = 'before_put_' . $routeName;
					if(function_exists($func)){
						$func($putData);
					}
				} catch (Exception $ex){
					
				}
				

				$route = $this->getAvailableRoutes()[$routeName];
				if(is_array($putData) && ResterUtils::isIndexed($putData) && count($putData) > 0) { //iterate on elements and try to update
					ResterUtils::Log("UPDATING MULTIPLE OBJECTS");
					foreach($putData as $updateObject) {
						ResterUtils::Log("UPDATING OBJECT ".$routeName." ID: ".$updateObject[$route->primaryKey->fieldName]);
						if(isset($updateObject[$route->primaryKey->fieldName])) {
							$currentResult = $this->updateObjectFromRoute($routeName, $updateObject[$route->primaryKey->fieldName], $updateObject);
							if(!empty($currentResult) && count($currentResult) == 1) $result[] = $currentResult[0];
						}
					}
					
					ResterUtils::Log("SUCCESS");
					
					//Register event hook
					try{
						$func = 'on_put_' . $routeName;
						if(function_exists($func)){
							$func($result);
						}
					} catch (Exception $ex){
						
					}
					
					$this->showResult($result);
					//$this->doResponse(ApiResponse::successResponse());
				} else {
					ResterUtils::Log("UPDATING SINGLE OBJECT");
					if(!isset($putData[$route->primaryKey->fieldName])) {
						ResterUtils::Log("No PRIMARY KEY FIELD ".$input);
						//echo $route->primaryKey->fieldName;
						$this->showError(400, "No key field supplied. Expecting: " . $route->primaryKey->fieldName);
					}	 
					$result = $this->updateObjectFromRoute($routeName, $putData[$route->primaryKey->fieldName], $putData);
					
					//Register event hook
					try{
						$func = 'on_put_' . $routeName;
						if(function_exists($func)){
							$func($result);
						}
					} catch (Exception $ex){
						
					}
					
					$this->showResult($result);
				}
			} else { //id from URL
		
				//parse_str($input, $putData);
				//$putData = json_decode($input, true);
				
				if(is_string($input)) parse_str($input, $putData);
				else $putData = $input;
				
			
					ResterUtils::Log("IS INDEXED");
					$result = $this->updateObjectFromRoute($routeName, $routePath[0], $putData);
					
					
					//Register event hook
					try{
						$func = 'on_put_' . $routeName;
						if(function_exists($func)){
							$func($result);
						}
					} catch (Exception $ex){
						
					}
					
					
					$this->showResult($result);
		
			}
					
			if($result > 0) {
				$this->doResponse(ApiResponse::successResponse());
			} else {
				$this->showResult($result);
			}
		
		});
	}
	
	/**
	 * Tries to get the post data of a request. Null if no post data is given
	 */
	function getPostData() {
		
		$body = $this->getRequestBody();
		
		if($body != NULL && is_array($body)) {
		  return $body;	
		} if (empty($_POST) === true) { //if empty, create a barebone object
			return NULL;
		} else if (is_array($_POST) === true) { 
			return $_POST;
		}
		
		return NULL;
	}

	/**
	 * This function checks if the request is CORS valid, if not checks for an authentication and setup the auth routes
	 */
	function checkOAuth() {
		
		global $validOrigins;
		
		if(isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $validOrigins)) {
			return;
		}

		//Command to generate the Request Tokens
		$this->addRouteCommand(new RouteCommand("POST", "auth", "requestToken", function($params = NULL) {
		
			if(empty($_POST["userId"])) {
				$this->showError(400);
			}
			
			$store = OAuthStore::instance('PDO', array('conn' => DBController::$db));
		
			$key = $store->updateConsumer($_POST, $_POST["userId"], true);
			$c = $store->getConsumer($key, $_POST["userId"]);
			
			$result["key"]=$c["consumer_key"];
			$result["secret"]=$c["consumer_secret"];
			
			$this->showResult($result);
			
		}, array("userId"), "Request a new token"));
		
		
		// Create a new instance of OAuthStore and OAuthServer
		$store = OAuthStore::instance('PDO', array('conn' => DBController::$db));
		$server = new OAuthServer();
		
		ResterUtils::Log(">> CHECKING OAUTH ".$_SERVER['REQUEST_METHOD']);
		
		if (OAuthRequestVerifier::requestIsSigned()) {
		
			//If the request is signed, allow from any source
			header('Access-Control-Allow-Origin: *');
		
			try {
				$req = new OAuthRequestVerifier();
				$id = $req->verify(false);
				ResterUtils::Log("*** API USER ".$id." ***");
			}  catch (OAuthException2 $e)  {
				// The request was signed, but failed verification
				header('HTTP/1.1 401 Unauthorized');
				header('WWW-Authenticate: OAuth realm=""');
				header('Content-Type: text/plain; charset=utf8');
				ResterUtils::Log(">> OAUTH ERROR >> ".$e->getMessage());
				
				$this->showError(401, 'Unauthorized. Signed but Failed Verification');
				exit();
			}	
		} else {
				
				ResterUtils::Log(">> OAUTH: Unsigned request");
				if(isset($validOrigins)) {
					foreach($validOrigins as $origin) {
						ResterUtils::Log(">> ADD ORIGIN: ".$origin);
						header('Access-Control-Allow-Origin: '.$origin);
					}
				} else {
					//TODO; CHECK ORIGIN
					header('HTTP/1.1 401 Unauthorized');
					header('WWW-Authenticate: OAuth realm=""');
					header('Content-Type: text/plain; charset=utf8');
					//echo "Authentication error";

					ResterUtils::Log(">> OAUTH ERROR >> Request not signed");
					ResterUtils::Log("*** AUTH ERROR *** ===>");
					
					$this->showError(401, 'Unauthorized. Request not signed. Please provide oauth_signature');					
					exit();
				}
			//$this->showError(401);
		}
    }
	
    /**
     * Parses the request body and decodes the json
     * @return object json parsed object
     */
	function getRequestBody() {
		if(empty($this->requestBody)){
			$this->requestBody = @file_get_contents('php://input');	
		}
		
		if(empty($this->requestBody)) {
			ResterUtils::Dump("ERR: Empty request body");
			return NULL;
		}
		
		ResterUtils::Dump($this->requestBody, "*** REQUEST BODY ***");
		
		return json_decode($this->requestBody, true);
	}
	
	/**
	 * Check if route is parsed
	 * @param string $routeName
	 * @return boolean true if route exists
	 */
	function checkRouteExists($routeName) {
		if(!isset($this->getAvailableRoutes()[$routeName])) {
				//Check for Custom Routes
				if($this->customRoutes["GET"][$routeName] || $this->customRoutes["POST"][$routeName]){
					return true;
				}

				$this->showError(404, "Requested route does not exist.");
				return false;
		}
		return true;
	}
	
	
	
	function checkRouteCommandExists($routeName, $command, $method) {
		$status = false;
		
		if(is_numeric($command)){
			$status = true;
		}
		
		if(($this->customRoutes[$method][$routeName] && $this->customRoutes[$method][$routeName][$command])){
			$status = true;
		}
		
		if(LEGACY_MODE){
			if($method == "POST"){
				if(in_array($command, array("get", "update", "delete"))){
					$status = true;	
				}
			}
		}
		
		if($status === true) return true;
		$this->showError(404);
		return false;
	}
	
	function addFileProcessor($routeName, $fieldName, $acceptedTypes = NULL) {
		if(isset($this->getAvailableRoutes()[$routeName])) {
			$this->getAvailableRoutes()[$routeName]->addFileProcessor($fieldName);	
		} else {
			//die("Can't add file processor ".$fieldName." to route ".$routeName);
			$this->showError(503, "File processor route '".$routeName."' is enabled, but can't find the table with name '".$routeName . "' or field with name '" . $fieldName . "'.");
		}
			
	}
	
	function addPublicMethod($requestMethod, $routeName) {
		$this->publicMethods[$requestMethod][]=$routeName;
	}
	
	function addRouteCommand($routeCommand) {
		
		ResterUtils::Log("+++ ADDING ROUTE COMMAND: ".$routeCommand->method." - ".$routeCommand->routeName." - ".$routeCommand->routeCommand);
		
		if($routeCommand->method == "DELETE" || $routeCommand->method == "PUT") {
			exit($routeCommand->method." is not supported on custom commands. Use GET or POST instead");
		}
	
		$routes = $this->getAvailableRoutes();
		
		
		//if(isset($routes[$routeCommand->routeName])) {
			$this->customRoutes[$routeCommand->method][$routeCommand->routeName][$routeCommand->routeCommand]=$routeCommand->callback;
			
			if(!isset($routes[$routeCommand->routeName])){
				$routes[$routeCommand->routeName]=NULL;
			}
			
			$route = $routes[$routeCommand->routeName];
			$route->routeCommands[$routeCommand->routeCommand]=$routeCommand;
			
			if(!isset($routes[$routeCommand->routeName]) && !isset($this->custom_routes[$routeCommand->routeName])){
				$this->custom_routes[$routeCommand->routeName]=new Route();
				$this->custom_routes[$routeCommand->routeName]->routeName = $routeCommand->routeName;
			}
			$this->custom_routes[$routeCommand->routeName]->routeCommands[$routeCommand->routeCommand] = $routeCommand;
			
			
		//}
		
	}
	
	function checkClientRestriction() {
		if ((empty($clients) !== true) && (in_array($_SERVER['REMOTE_ADDR'], (array) $clients) !== true))
		{
			exit($this->dbController->Reply(ApiResponse::errorResponse(403)));
		}
	}
	
	function addRequestProcessor($requestMethod, $callback) {
		$this->requestProcessors[$requestMethod][]=$callback;
	}
		
	/**
	* Add CORS headers or check for OAuth authentication
	*/
	function checkAuthentication() {
		global $validOrigins;
		
		if(isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $validOrigins)) {
			ResterUtils::Log(">> ADD ORIGIN: ".$_SERVER['HTTP_ORIGIN']);
			header('Access-Control-Allow-Origin: '.$_SERVER['HTTP_ORIGIN']);
		}
	
		if(!defined('ENABLE_OAUTH') || !ENABLE_OAUTH)
			return;
	
		if(!isset($this->publicMethods[$_SERVER['REQUEST_METHOD']])) {
			if($requestMethod !== "OPTIONS")
				$this->checkOAuth();
		} else {
			$publicRoutes = $this->publicMethods[$_SERVER['REQUEST_METHOD']];
			if(!in_array($this->getRoutePath(), $publicRoutes) && !in_array($this->getCurrentRoute(), $publicRoutes))
				$this->checkOAuth();
			else
				ResterUtils::Log("*** PUBLIC ROUTE ==> ".$this->getRoutePath());
		}
	}
		
	function processRequest($requestMethod) {
	
		ResterUtils::Log("*** BEGIN PROCESSING REQUEST ".$requestMethod." *** ==> ".$this->getRoutePath());
		
		$this->checkAuthentication();
		
		if(isset($this->requestProcessors[$requestMethod])) {
			
			
			foreach($this->requestProcessors[$requestMethod] as $callback) {
			
				ResterUtils::Log(">> Found processor callback");
			
				$callbackParameters = array();
				
				if($this->getCurrentRoute() && $this->getCurrentRoute() != "/") {
					$callbackParameters[0] = $this->getCurrentRoute();
					
					// if(!empty($this->getRoute($callbackParameters[0])) && empty($this->getRoute($callbackParameters[0])->primaryKey)){
					//     $this->showError(422, "Could not find a primary key with autoincrement for API route /$callbackParameters[0]");
					// }
					
					ResterUtils::Log(">> Processing route /".$this->getCurrentRoute());
					if(count($this->getCurrentPath()) > 0) {
						$callbackParameters[1]=$this->getCurrentPath();
						ResterUtils::Log(">> Processing command ".implode("/",$this->getCurrentPath()));
					} else {
						$callbackParameters[1] = NULL;
					}
					
                    $route = $this->getRoute($callbackParameters[0]);
					if(!empty($route)){
					    if((!empty($route->routeCommands) && !empty($route->routeCommands[$callbackParameters[1][0]]))){
					    } else if(empty($route->primaryKey)) {
					        $this->showError(422, "Could not find a primary key with autoincrement for API route /$callbackParameters[0]");
					    }
					}
					
					if($requestMethod == "GET")
						parse_str($_SERVER['QUERY_STRING'], $requestParameters);
					else if($requestMethod == "POST")
						$requestParameters = $_POST;
						
					if(isset($requestParameters)) {
						$callbackParameters[2] = $requestParameters;
						ResterUtils::Log(">> PARAMETERS: ".http_build_query($requestParameters));
						ResterUtils::Dump($requestParameters);
					}
				}
								
				try {
					if(isset($callbackParameters) && count($callbackParameters) > 0) {
						//call_user_func_array($callback, $callbackParameters);
						
						//LEGACY MODE
						//if(LEGACY_MODE){
							if($requestMethod == "POST"){
								$overrideParam = 'X-HTTP-Method-Override';
								$requestMethodOverride = $_REQUEST[$overrideParam];
								if(in_array($requestMethodOverride, array("PUT", "DELETE", "GET"))){
									$callbackOverride = $this->requestProcessors[$requestMethodOverride];	
								}
							}
						//}
						
						if($callbackOverride){
							call_user_func_array($callbackOverride, $callbackParameters);
						} else {
							call_user_func_array($callback, $callbackParameters);
						}

					} else {
						call_user_func($callback);
					}
				} catch(Exception $e) {
					if(API_EXCEPTIONS_IN_RESPONSE){
						if(!empty($e->errorInfo) && is_array($e->errorInfo) && count($e->errorInfo) > 2){
						    if($e->errorInfo[0] == "42S22" && $e->errorInfo[1] == 1054){
						        $this->showError(422, "Supplied criteria parameters do not exist.", $e);
						    }
						    if($e->errorInfo[0] == "23000" && $e->errorInfo[1] == 1062){
						        $this->showError(409, $e->errorInfo[2], $e);
						    }
						    
						}
						$this->showError(500, null, $e);
					} else {
						return false;
					}
				}
				//Handle in case custom methods do not send a response
				//exit();
			}
		} else {
			ResterUtils::Log("*** ERROR *** Request processor not set ".$requestMethod);
			$this->showError(405);
		}
	}
	
	//TODO
	function checkConnectionStatus() {
		if(is_null(DBController::$db))
                {
                        $this->showError(503, "Could not connect to the database. Please check your configurations.");
                }
		/*if ($this->dbController->Query(DSN) === false) {
			exit($this->dbController->Reply(ApiResponse::errorResponseWithMessage(503, "Error connecting to SQL")));	
		}*/
	}
	
	/*************************************/
	/* OBJECT MANAGEMENT METHODS
	/*************************************/	
	function create($route, $object, $force = false){
		if($force){
			return $this->insertObject($route, $object);
		} else {
			try {
				return $this->insertObject($route, $object);
			} catch(Exception $ex) {
				return null;
			}
		}
	}
	
	function insertObject($routeName, $objectData) {

		$route = $this->getAvailableRoutes()[$routeName];
	
		$routeFieldNames = $route->getFieldNames(TRUE);
		
		if(in_array("uuid", $routeFieldNames)) {
			$objectData["uuid"] = UUID::v4();
		} 
			
		if(in_array("lastmoddate", $routeFieldNames)) {
			$objectData["lastmoddate"] = time() * 1000;
		}
			
		if(in_array("createddate", $routeFieldNames)) {
			$objectData["createddate"] = time() * 1000;
		}
		
		//Set the object ID
		$insertID = $route->getInsertIDFromObject($objectData);
		$objectData[$route->primaryKey->fieldName] = $insertID;

		//Insert the object into database
		$result = $this->dbController->insertObjectToDB($route, $objectData);

		if($result == 0) { //No insert id
			$result = $insertID;
		}
		
		ResterUtils::Log("RESULT: **** ".$result);
	
		//If we have files on the object, process them
		$this->processFilesWithID($route, $result);
		
		$this->processInsertRelations($route, $objectData);
		
		//Get the object model by ID
		$object = $this->getObjectByID($routeName,$result);
		
		if(is_array($object) && ResterUtils::isIndexed($object)) {
			$object=$object[0];
		}
		
		
		
		return $object;
	}
	
	private function processInsertRelations($route, $objectData) {

		ResterUtils::Log("--- ROUTE FIELDS ---");
		//ResterUtils::Dump($route->routeFields);
		
		foreach ($objectData as $key => $value) {
			//Check for relations on insert
			if($route->routeFields[$key]->isRelation) {
				
				if($route->routeFields[$key]->fieldType == "json") {
					$relation = $route->routeFields[$key]->relation;
					
					$destinationRoute = $this->getRoute($relation->destinationRoute);
				
					$objectID = $value[$destinationRoute->primaryKey->fieldName];
				
					$value[$relation->relationName]=json_encode($value[$relation->relationName]);
				
					$this->updateObjectFromRoute($destinationRoute->routeName, $objectID, $value);
					
					$this->updateObjectFromRoute($route->routeName, $objectData[$route->primaryKey->fieldName], array($relation->field => $objectID));
				}
			} 
		}
	}
	
	/**
	 * This method looks for uploaded files. If we have, get the ID of the object, and update the database according the upload field
	 * @param Route $route
	 * @param object $objectID
	 */
	private function processFilesWithID($route, $objectID) {
		//Process files
		if(count($_FILES) > 0) { //we got files... process them
			foreach($_FILES as $fileField => $f) {
				if($route->getFileProcessor($fileField) != NULL) { //We have to process
					$processor = $route->getFileProcessor($fileField);
					$upload = $processor->saveUploadFile($objectID, $route->routeName, $f);
					$newData = array ($route->primaryKey->fieldName => $objectID, $fileField => $upload["destination"]);
					$this->updateObjectFromRoute($route->routeName, $objectID, $newData);
				}
			}
		}
	}
	
	function find($route_name, $filters = NULL, $match_any = false){
		return $this->getObjectsFromRoute($this->getAvailableRoutes()[$route_name], $filters, $match_any);
	}
	
	function getObjectsFromRouteName($routeName, $filters = NULL, $orFilter = false) {
		return $this->getObjectsFromRoute($this->getAvailableRoutes()[$routeName], $filters, $orFilter);
	}
	
	function getObjectsFromRoute($route, $filters = NULL, $orFilter = false) {
		$this->NESTED_COUNTER++;
		
		if(isset($filters['api_key'])) unset($filters['api_key']);
		if(isset($filters['api-key'])) unset($filters['api-key']);		
		
		
		if(function_exists('request_headers_remove')){
			$headers_for_removal = request_headers_remove();
			
			foreach($headers_for_removal as $h){
				if(isset($filters[$h])) unset($filters[$h]);
			}
		}
		
		$result = $this->dbController->getObjectsFromDB($route, $filters, $this->getAvailableRoutes(), $orFilter);
		
		
		
		/*if(count($route->getRelationFields(TRUE)) > 0) {
		
			foreach($route->getRelationFields() as $rf) {
				
				$relationClass = get_class($rf);
				
			
						$destinationRoute = $this->getAvailableRoutes()[$rf->relation->destinationRoute];
						
						foreach($destinationRoute->getRelationFieldNames($rf->relation) as $fieldKey => $rName) {
							$relationFieldNames[] = $rf->relation->relationName.".".$fieldKey." as ".$rName;
						}
				
			}
		}
		
		$query[] = "SELECT ";
		
		$query[] = implode(",", $route->getFieldNames(FALSE, TRUE));
		
		if(isset($relationFieldNames)) {
			$query[] = ",";
			$query[] = implode(",",$relationFieldNames);
		}
		
		$query[] = " FROM `".$route->routeName."` as ".$route->routeName;
		
		//$query = array(sprintf('SELECT * FROM "%s"', $route->routeName));
		
		if(count($route->getRelationFields(TRUE)) > 0) {
			foreach($route->getRelationFields() as $relationField) {
				$query[] = ",".$relationField->relation->destinationRoute." as ".$relationField->relation->relationName;
			}
		}

		$i = 0;
		
		if(isset($fieldFilters)) {
			
			$closeBracket = false;
			
			foreach($fieldFilters as $filterField => $filterValue) {
			
				if($i == 0) {
					$q = "WHERE ("; 
					$closeBracket = true;
				} else {
					if($orFilter) {
						$q = "OR";
					} else {
						$q = "AND";
					}
				}
				
				$q .= " (".$route->routeName.".".$filterField." ";
						
				if(is_array($filterValue)) {
					$queryType = array_keys($filterValue)[0];
					$queryValue =$filterValue[$queryType];	
					
					$val = explode(",", $queryValue);
							
					switch($queryType) {
						case "in":
							$q.="LIKE '%".$queryValue."%'";
						break;
						case "gt":
							$q.="> ".$queryValue;
						break;
						case "lt":
							$q.="< ".$queryValue;
						break;
						case "ge":
							$q.=">= ".$queryValue;
						break;
						case "le":
							$q.="<= ".$queryValue;
						break;
						default:
							$q.="= '".$queryValue."'";
						break;
					}
					
				} else {
	
					$val = explode(",", $filterValue);
	
					//search mode
					$q.="= '".$val[0]."'";
					
					for($i = 1; $i<count($val); $i++) {
						$q.=" OR ".$route->routeName.".".$filterField." = '".$val[$i]."'";	
					}
				}
				
				$q.= ")";				
				
				$query[] = $q;
				
				$i++;
			}
		}
		
		if($closeBracket)
			$query[] = ")";
		
		//JOINS
		if(count($route->getRelationFields(TRUE)) > 0) { //get relation fields, skipping non joinable
			if(!isset($fieldFilters) || count($fieldFilters) == 0) {
				$query[] = " WHERE ";
			} else {
				$query[] = " AND ";
			}
			$query [] = "(";
			$i = 0;
			foreach($route->getRelationFields() as $relationField) {
					if($i > 0)
						$query[] = "AND";
					$query[] = " ".$route->routeName.".".$relationField->relation->field." = ".$relationField->relation->relationName.".".$relationField->relation->destinationField." ";
					$i++;
				
			}
			$query [] = ")";
		}
		
		if (isset($order['by']) === true)
		{
			if (isset($order['order']) !== true)
			{
				$order['order'] = 'ASC';
			}

			$query[] = sprintf('ORDER BY "%s" %s', $order['by'], $order['order']);
		}

		if (isset($filters['limit']) === true)
		{
			$query[] = sprintf('LIMIT %u', $filters['limit']);

			if (isset($filters['offset']) === true)
			{
				$query[] = sprintf('OFFSET %u', $filters['offset']);
			}
		} else { //Default limit
			$query[] = "LIMIT 1000";
		}
		
		$query = sprintf('%s;', implode(' ', $query));
		
		$result = $this->dbController->Query($query);*/
		
		//Process Raw Objects
		//$response = $this->processRawObjects($route, $result);
		
		//Don't process Raw object for count only queries, otherwise process
		if($filters['count']) {
			$response = $result;
		} else
		{
			$response = $this->processRawObjects($route, $result);
		}

		
		if(!isset($response)) {
			return NULL;
		}
		return $response;
	}
	
	function processRawObjects($route, $rawObjects) {
		
		foreach($rawObjects as $row) { //Iterate over rows of results
			//ResterUtils::Dump($row);
			//Clean array values
			$mainObject = ResterUtils::cleanArray($row, $route->getFieldNames(FALSE, FALSE));

		
			//Map objects to it's correct types
			$mainObject = $route->mapObjectTypes($mainObject);
			
			
			if(count($route->getRelationFields()) > 0) {
				foreach($route->getRelationFields() as $rf) {				
					if(($rf->relation->inverse && $route->routeName == $rf->relation->destinationRoute) 
					 || ($rf->fieldType == "json" && !$rf->relation->inverse)) {
						
						$jsonObject = json_decode($row[$rf->relation->relationName]);
						
						if(!$jsonObject)
							$jsonObject=array();
						
						$mainObject[$rf->relation->relationName]=$jsonObject;
						
					} else {
						$destinationRoute = $this->getAvailableRoutes()[$rf->relation->destinationRoute];
			
						$relationObject = array();
					
						foreach($destinationRoute->getRelationFieldNames($rf->relation) as $fieldKey => $rName) {
							$relationObject[$fieldKey]=$row[$rName];
						}
						

						$relationObject = $destinationRoute->mapObjectTypes($relationObject);

						
						if(ENABLE_DEEP_QUERY == true){
							$destinationRelationFields = $destinationRoute->getRelationFields();
							if(count($destinationRelationFields) > 0){
								$proceed = true;
								if($this->NESTED_COUNTER > MAX_NESTING_LEVEL){ $proceed = false; }
								foreach($destinationRelationFields as &$drf){
									if($drf->fieldType == $route->routeName){
										$proceed = false;
									}
								}
								if($proceed == true){
									$subFilter = array($destinationRoute->primaryKey->fieldName => $relationObject[$destinationRoute->primaryKey->fieldName]);
									$rawObj = $this->getObjectsFromRoute($destinationRoute, $subFilter);
									$this->NESTED_COUNTER--;
									$relationObject=$rawObj[0];
								}
							}
						}
						
						
						$relationFieldName = $rf->relation->field;
						if(string_endswith($relationFieldName, "_id")) $relationFieldName = str_replace("_id","",$relationFieldName);
						if(string_endswith($relationFieldName, "Id")) $relationFieldName = str_replace("Id","",$relationFieldName);
						if(!empty($relationObject[$rf->relation->destinationField])) {
							$mainObject[$relationFieldName]=$relationObject;
						} else {
							$mainObject[$relationFieldName] = null;
						}
						
						if($relationFieldName != $rf->relation->field) { 
							$mainObject[$rf->relation->field]=$relationObject[$rf->relation->destinationField]; 
						}
						
						//$mainObject[$rf->relation->destinationRoute]=$relationObject;

					}
				}
			}
				
			$response[]=$mainObject;
		}
		if(isset($response))
			return $response;
		
		return NULL;
	}
	
	function query($query) {
		return $this->dbController->Query($query);
	}
	
	
	function findOne($route, $id){
		$result = $this->getObjectByID($route, $id);
		if(empty($result)) return null;
		return $result[0];
	}
		
	function getObjectByID($routeName, $ID) {

		$route = $this->getAvailableRoutes()[$routeName];
		
		if(is_array($ID)) {
			$ID=implode(",", $ID);
		}
		
		$filter = array($route->primaryKey->fieldName => $ID);
			
		$result = $this->getObjectsFromRoute($route, $filter);
			
		return $result;	
	}
	
	function delete($route, $id, $force = false){
		if($force){
			return $this->deleteObjectFromRoute($route, $id);	
		} else {
			try{
				return $this->deleteObjectFromRoute($route, $id);	
			} catch(Exception $ex) {
				return null;
			}
		}
	}
	
	function deleteObjectFromRoute($routeName, $ID) {
		$key = $this->getRoute($routeName)->primaryKey->fieldName;
		$query = array(
			sprintf('DELETE FROM "%s" WHERE "%s" = ?', $routeName, $key)
		);

		$query = sprintf('%s;', implode(' ', $query));
	
		$result = $this->dbController->Query($query, $ID);
		
		return empty($result) ? $result : $ID;// array($key => $ID, "status" => "deleted");
	}
	
	function update($route, $id, $object, $force = false) {
		if($force){
			$currentRoute = $this->getAvailableRoutes()[$route];
			$this->dbController->updateObjectOnDB($currentRoute, $id, $object);
			return $this->findOne($route, $id);
		} else {
			try{
				$currentRoute = $this->getAvailableRoutes()[$route];
				$this->dbController->updateObjectOnDB($currentRoute, $id, $object);
				return $this->findOne($route, $id);
			} catch (Exception $ex){
				return null;
			}
		}
	}
	
	function updateObjectFromRoute($routeName, $objectID, $newData) {
		ResterUtils::Log("UPDATING OBJECT");
		if (empty($newData) === true) {
			$this->showError(400, "The request body is empty.");
		}
		
		$currentRoute = $this->getAvailableRoutes()[$routeName];

		
		if(is_array($newData) === true) {
			if(!empty($objectID) && $objectID > 0){
				$existing = $this->getObjectsFromRouteName($routeName, array($currentRoute->primaryKey->fieldName => $objectID));
				if(empty($existing)){
					$this->showError(404, "Could not find the object you are trying to update.");
				}
			} else{
				$this->showError(400, "The request body is not in acceptable format.");
			}
			

			$relations = $currentRoute->getRelationFields();
			foreach($relations as $relation){
				$relationFieldName = $relation->relation->field;
				if(string_endswith($relationFieldName, "_id")) $relationFieldName = str_replace("_id","",$relationFieldName);
				if(string_endswith($relationFieldName, "Id")) $relationFieldName = str_replace("Id","",$relationFieldName);
				if($relationFieldName != $relation->relation->field){
					//if(isset($newData[$relationFieldName])) {
						unset($newData[$relationFieldName]);
					//}
				}
			}

			$this->dbController->updateObjectOnDB($currentRoute, $objectID, $newData);
			return $this->getObjectByID($routeName, $objectID);
		} else {
			$this->showError(400, "The request body is not in acceptable format.");
		}
	}
	
	function processFiles($route, $objectID) {
			//Process files
		if(count($_FILES) > 0) { //we got files... process them
			foreach($_FILES as $fileField => $f) {
				if($route->getFileProcessor($fileField) != NULL) { //We have to process
					$processor = $route->getFileProcessor($fileField);
					$upload = $processor->saveUploadFile($objectID, $route->routeName, $f);
					$newData = array ($route->primaryKey->fieldName => $objectID, $fileField => $upload["destination"]);
					$this->updateObjectFromRoute($route->routeName, $objectID, $newData);
				}
			}
		}
	}
	
	/*************************************/
	/* RETURN DATA FUNCTIONS
	/*************************************/
	
	function showError($errorNumber, $message = NULL, $exception = NULL) {
		// if(empty($message)){
		// 	$result = ApiResponse::errorResponse($errorNumber);
		// 	exit($this->doResponse($result));
		// } else {
		// 	$result = ApiResponse::errorResponseWithMessage($errorNumber, $message);
		// 	exit($this->doResponse($result));
		// }
		$result = ApiResponse::errorResponse($errorNumber, $message, $exception);
		exit($this->doResponse($result));
	}
	
	function showErrorWithMessage($errorNumber, $message) {
		$result = ApiResponse::errorResponse($errorNumber, $message);
		exit($this->doResponse($result));
	}

	
	function showResult($result, $code = 200,$forceArray = false) {
	
		ResterUtils::Log("*** DISPLAY RESULT TO API ***");
	
		if (empty($result) === true) {
			$this->showError(204);
		} else if ($result === false || count($result) == 0) {
			$this->showError(404);
		} else if($result === true || (is_int($result) && $result >= 1) ) {
			$this->doResponse(ApiResponse::successResponse($result));
		} else {
			if(is_array($result) && count($result) == 1 && !$forceArray && ResterUtils::isIndexed($result)) {
				$this->doResponse($result[0], $code);
			} else
				$this->doResponse($result, $code);
		}
	}
	
	private function doResponse($data, $responseCode = 200) {
	
		if(isset($data["error"])) {
			ResterUtils::Log(">> Error Response: ".$data["error"]["code"]." ".$data["error"]["status"]." - ".$this->getCurrentRoute());
			header("HTTP/1.1 ".$data["error"]["code"]." ".$data["error"]["status"], true, $data["error"]["code"]);
		}
	
		if($responseCode != 200) {
			switch($responseCode) {
				case 201:
					header("HTTP/1.1 ".$responseCode." Created");
				break;
			}
		}
	
		$bitmask = 0;
		$options = array('UNESCAPED_SLASHES', 'UNESCAPED_UNICODE');

		if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) === true)
		{
			$options[] = 'PRETTY_PRINT';
		}

		foreach ($options as $option)
		{
			$bitmask |= (defined('JSON_' . $option) === true) ? constant('JSON_' . $option) : 0;
		}

		if (($result = json_encode($data, $bitmask)) !== false)
		{
			$callback = null;

			if (array_key_exists('callback', $_GET) === true)
			{
				$callback = trim(preg_replace('~[^[:alnum:]\[\]_.]~', '', $_GET['callback']));

				if (empty($callback) !== true)
				{
					$result = sprintf('%s(%s);', $callback, $result);
				}
			}

			if (headers_sent() !== true)
			{
				header(sprintf('Content-Type: application/%s; charset=utf-8', (empty($callback) === true) ? 'json' : 'javascript'));
			}
		}

		//ResterUtils::Dump($result);
		
		exit($result);
	}
	
	function showRoutes() {
		$routes = $this->getAvailableRoutes();

		$custom_routes = $this->getAvailableCustomRoutes();
		
		
		$result = SwaggerHelper::routeResume($routes, $custom_routes);
		$this->doResponse($result);
	}
	
	/*************************************/
	/* ROUTE PARSING METHODS
	/*************************************/
	
	public function getRoot() {
		//Original Code
		$root = preg_replace('~/++~', '/', substr($_SERVER['PHP_SELF'], strlen($_SERVER['SCRIPT_NAME'])) . '/');

		//In case PHP_SELF does not work
		$request_uri = $_SERVER['REQUEST_URI'];
		$script_path = $_SERVER['PHP_SELF'];

		//$apiPrefix = string_intersect($script_path, $request_uri);

		$script_name = "/index.php";
		$script_pos = strpos($script_path, $script_name);
		$apiPrefix = substr($script_path, 0, $script_pos);
		$apiPrefix = string_intersect($apiPrefix, $request_uri);

		$root = substr($request_uri, strlen($apiPrefix));

		$pos = strpos($root, '?');
		if($pos > -1) $root = substr($root,0, $pos);
		if($root[strlen($root) - 1] != '/') $root = $root . '/';

		return $root;
	}
	
	public function getCurrentRoute() {
		$routePath = array_filter(explode("/", $this->getRoot()));
		if(count($routePath) > 0)
			return array_values($routePath)[0];
		else
			return false;
	}
	
	public function getCurrentPath() {
		return array_values(array_filter(array_slice(explode("/", $this->getRoot()), 2)));
	}
	
	public function getRoutePath() {
		if(count($this->getCurrentPath()) > 0)
			return $this->getCurrentRoute()."/".implode("/",$this->getCurrentPath());
		else
			return $this->getCurrentRoute();
	}
	
	/**
	* Search the tables of the DB and configures the routes
	*/
	function getAvailableRoutes() {
		if($this->routes == NULL) {
			$this->routes = $this->dbController->getRoutes();
		}
		return $this->routes;
	}
	
	function getAvailableCustomRoutes() {
		return $this->custom_routes;
	}

	function getAvailableProcRoutes() {
		return $this->stored_procedures;
	}
	
	function getAvailableNavRoutes() {
		if(empty($this->nav_routes)){
			$query = "SELECT REFERENCED_TABLE_NAME as parent, REFERENCED_COLUMN_NAME as parent_key, TABLE_NAME as children, COLUMN_NAME as reference_key FROM information_schema.KEY_COLUMN_USAGE where REFERENCED_TABLE_NAME is not NULL";
			 $result = $this->query($query);
			 foreach($result as $r){
			 	$this->nav_routes[$r["parent"]][] = array($r["children"] => $r["reference_key"]);
			 }
			 //$this->nav_routes = $result;
		}
		return $this->nav_routes;
	}
	
	function getNavRoute($routeName){
		return $this->getAvailableNavRoutes()[$routeName];
	}
	
	
	function getRoute($routeName) {
		$routes = $this->getAvailableRoutes();
		if(isset($routes[$routeName]))
			return $routes[$routeName];
			
		return NULL;
	}
	
	function getColumnsFromTable($table) {
		$result = $this->dbController->Query("DESCRIBE ".$table);
		return $result;
	}
	
	
	
	/**
 * Parse raw HTTP request data
 *
 * Pass in $a_data as an array. This is done by reference to avoid copying
 * the data around too much.
 *
 * Any files found in the request will be added by their field name to the
 * $data['files'] array.
 *
 * @param   array  Empty array to fill with data
 * @return  array  Associative array of request data
 */
	function parse_raw_http_request($input, array &$a_data)
{
   
  // grab multipart boundary from content type header
  preg_match('/boundary=(.*)$/', $_SERVER['CONTENT_TYPE'], $matches);
   
  // content type is probably regular form-encoded
  if (!count($matches))
  {
    // we expect regular puts to containt a query string containing data
    parse_str(urldecode($input), $a_data);
    return $a_data;
  }
   
  $boundary = $matches[1];
 
  // split content by boundary and get rid of last -- element
  $a_blocks = preg_split("/-+$boundary/", $input);
  array_pop($a_blocks);
  
  // loop data blocks
  foreach ($a_blocks as $id => $block)
  {
    if (empty($block))
      continue;
     
    // you'll have to var_dump $block to understand this and maybe replace \n or \r with a visibile char
     
    // parse uploaded files
    if (strpos($block, 'application/octet-stream') !== FALSE)
    {
      // match "name", then everything after "stream" (optional) except for prepending newlines
      preg_match("/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s", $block, $matches);
      $a_data['files'][$matches[1]] = $matches[2];
    }
    // parse all other fields
    else
    {
      // match "name" and optional value in between newline sequences
      preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
      $a_data[$matches[1]] = $matches[2];
    }
  }
}






//Helper Functions

function getURL($url, $params = null, $headers = null, $unsafe = false){
	return url_get($url, $params, $headers, $unsafe);
}

function postURL($url, $payload = null, $headers = null, $unsafe = false){
	return url_post($url, $payload, $headers, $unsafe);
}

function prepareMail($template, $data){
	return prepare_email_body($template, $data);
}

function sendMail($from, $to, $subject, $body, $smtp, $debug=false, $cc = array(), $bcc = array(), $from_name = "", $to_names = array(), $reply_to = "", $reply_to_name = ""){
	return send_email_smtp($from, $to, $subject, $body, $smtp, $debug, $cc, $bcc, $from_name, $to_names, $reply_to, $reply_to_name);
}

function sendMailSparkPost($from, $to, $subject, $body, $api_key){
	return send_email_sparkpost($from, $to, $subject, $body, $api_key);
}

function uuid() {
	return uuid();
}

function intersectString($string_1, $string_2){
	return string_intersect($string_1, $string_2);
}

function startsWith($str, $key){
	return string_startswith($str, $key);	
}

function endsWith($str, $key){
	return string_endswith($str, $key);	
}

function where($array, $column_name, $where, $single=true, $return_only_key = false) {
	return array_search_where($array, $column_name, $where, $single, $return_only_key);
}

function requestIsMobile(){
	return request_is_mobile();
}

function requestRoute(){
	return get_current_api_path();
}

function register($method = "POST", $route = "custom", $path, $handler, $required_parameters = array(), $description = "Custom API"){
	$allowedMethods = array("GET", "POST");
	$suppliedMethod = strtoupper($method);
	if(!in_array($suppliedMethod, $allowedMethods)){
		$this->showError(503, "Error registering cutom route '$suppliedMethod $route/$path'. Allowed methods are " . implode(",", $allowedMethods) . ".");
	}
	
	if(empty($route) || empty($path)){
		$this->showError(503, "Error registering cutom route '$suppliedMethod $route/$path' due to invalid API route");
	}
	
	$this->addRouteCommand(new RouteCommand($method, $route, $path, $handler, $required_parameters, $description));
}

function successResponse($data = null, $statusCode = 200) {
	return ApiResponse::successResponse($data, $statusCode);
}

function errorResponse($errorCode, $message = null) {
	return ApiResponse::errorResponse($errorCode, $message);
}

function encrypt($text, $key){
	return encrypt($text, $key);
}

function decrypt($text, $key){
	return decrypt($text, $key);
}

function generateCryptoKey(){
	return generate_encryption_key();
}

function diff($obj1, $obj2){
	return get_diff_both($obj1, $obj2);
}
	
function now(){
	return date("Y-m-d H:i:s");
}
	
function today(){
	return date("Y-m-d");
}	

function toDate($datetime){
	return date("Y-m-d", strtotime($datetime));
}


} //END

?>
