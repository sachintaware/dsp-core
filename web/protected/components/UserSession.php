<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Sql;
use Platform\Interfaces\PermissionTypes;
use Platform\Yii\Utility\Pii;
use Swagger\Annotations as SWG;

/**
 * UserSession
 * DSP user session
 *
 * @package
 * @category
 *
 * @SWG\Resource(
 *   apiVersion="1.0.0",
 *   swaggerVersion="1.1",
 *   basePath="http://localhost/rest",
 *   resourcePath="/user"
 * )
 *
 * @SWG\Model(id="Session",
 *   @SWG\Property(name="id",type="string",description="Identifier for the current user."),
 *   @SWG\Property(name="email",type="string",description="Email address of the current user."),
 *   @SWG\Property(name="first_name",type="string",description="First name of the current user."),
 *   @SWG\Property(name="last_name",type="string",description="Last name of the current user."),
 *   @SWG\Property(name="display_name",type="string",description="Full display name of the current user."),
 *   @SWG\Property(name="is_sys_admin",type="boolean",description="Is the current user a system administrator."),
 *   @SWG\Property(name="last_login_date",type="string",description="Date and time of the last login for the current user."),
 *   @SWG\Property(name="app_groups",type="Array",description="App groups and the containing apps."),
 *   @SWG\Property(name="no_group_apps",type="Array",description="Apps that are not in any app groups."),
 *   @SWG\Property(name="ticket",type="string",description="Timed ticket that can be used to start a separate session."),
 *   @SWG\Property(name="ticket_expiry",type="string",description="Expiration time for the given ticket.")
 * )
 *
 * @SWG\Model(id="Login",
 *   @SWG\Property(name="email",type="string"),
 *   @SWG\Property(name="password",type="string")
 * )
 *
 */
class UserSession extends RestResource
{
	/**
	 * @var string
	 */
	protected static $_randKey;

	/**
	 * @var null
	 */
	protected static $_userId = null;

	/**
	 * @var array
	 */
	protected static $_cache = null;

	/**
	 * Create a new UserSession
	 *
	 */
	public function __construct()
	{
		$config = array(
			'service_name'=> 'user',
			'name'        => 'User Session',
			'api_name'    => 'session',
			'description' => 'Resource for a user to manage their session.',
			'is_active'   => true,
		);
		parent::__construct( $config );

		//For better security. Get a random string from this link: http://tinyurl.com/randstr and put it here
		static::$_randKey = 'M1kVi0kE9ouXxF9';
	}

	// Service interface implementation

	public function setApiName( $apiName )
	{
		throw new Exception( 'UserSession API name can not be changed.' );
	}

	public function setType( $type )
	{
		throw new Exception( 'UserSession type can not be changed.' );
	}

	public function setDescription( $description )
	{
		throw new Exception( 'UserSession description can not be changed.' );
	}

	public function setIsActive( $isActive )
	{
		throw new Exception( 'UserSession active flag can not be changed.' );
	}

	public function setName( $name )
	{
		throw new Exception( 'UserSession name can not be changed.' );
	}

	// REST interface implementation

	protected function _handleAction()
	{
		switch ( $this->_action )
		{
			case self::Get:
				$ticket = Utilities::getArrayValue( 'ticket', $_REQUEST, '' );
				$result = $this->userSession( $ticket );
				break;
			case self::Post:
				$data = Utilities::getPostDataAsArray();
				$email = Utilities::getArrayValue( 'email', $data, '' );
				$password = Utilities::getArrayValue( 'password', $data, '' );
				//$password = Utilities::decryptPassword($password);
				$result = $this->userLogin( $email, $password );
				break;
			case self::Delete:
				$this->userLogout();
				$result = array( 'success' => true );
				break;
			default:
				return false;
		}

		return $result;
	}

	//-------- User Operations ------------------------------------------------

	/**
	 * userSession refreshes an existing session or
	 *     allows the SSO creation of a new session for external apps via timed ticket
	 *
	 * @SWG\Api(
	 *   path="/user/session", description="Operations on a user's session.",
	 *   @SWG\Operations(
	 *     @SWG\Operation(
	 *       httpMethod="GET", summary="Retrieve the current user session information.",
	 *       notes="Calling this refreshes the current session, or returns an error for timed-out or invalid sessions.",
	 *       responseClass="Session", nickname="getSession",
	 *       @SWG\ErrorResponses(
	 *          @SWG\ErrorResponse(code="401", reason="Unauthorized Access - No currently valid session available."),
	 *          @SWG\ErrorResponse(code="500", reason="System Error - Specific reason is included in the error message.")
	 *       )
	 *     )
	 *   )
	 * )
	 *
	 * @param string $ticket
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public static function userSession( $ticket = '' )
	{
		if ( empty( $ticket ) )
		{
			try
			{
				$userId = static::validateSession();
			}
			catch ( Exception $ex )
			{
				static::userLogout();

				// special case for possible guest user
				$theConfig = Config::model()->with(
					'guest_role.role_service_accesses',
					'guest_role.apps',
					'guest_role.services'
				)->find();

				if ( !empty( $theConfig ) )
				{
					if ( Utilities::boolval( $theConfig->allow_guest_user ) )
					{
						$result = static::generateSessionDataFromRole( null, $theConfig->getRelated( 'guest_role' ) );

						// additional stuff for session - launchpad mainly
						return static::addSessionExtras( $result, false, true );
					}
				}

				// otherwise throw original exception
				throw $ex;
			}
		}
		else
		{ // process ticket
			$creds = Utilities::decryptCreds( $ticket, "gorilla" );
			$pieces = explode( ',', $creds );
			$userId = $pieces[0];
			$timestamp = $pieces[1];
			$curTime = time();
			$lapse = $curTime - $timestamp;
			if ( empty( $userId ) || ( $lapse > 300 ) )
			{ // only lasts 5 minutes
				static::userLogout();
				throw new Exception( "Ticket used for session generation is too old.", ErrorCodes::UNAUTHORIZED );
			}
		}

		try
		{
			$theUser = User::model()->with( 'role.role_service_accesses', 'role.apps', 'role.services' )->findByPk( $userId );
			if ( null === $theUser )
			{
				throw new Exception( "The user identified in the session or ticket does not exist in the system.", ErrorCodes::UNAUTHORIZED );
			}
			$isSysAdmin = $theUser->is_sys_admin;
			$result = static::generateSessionDataFromUser( null, $theUser );

			// additional stuff for session - launchpad mainly
			return static::addSessionExtras( $result, $isSysAdmin, true );
		}
		catch ( Exception $ex )
		{
			throw $ex;
		}
	}

	/**
	 *
	 * @SWG\Api(
	 *   path="/user/session", description="Operations on a user's session.",
	 *   @SWG\Operations(
	 *     @SWG\Operation(
	 *       httpMethod="POST", summary="Login and create a new user session.",
	 *       notes="Calling this creates a new session and logs in the user.",
	 *       responseClass="Session", nickname="login",
	 *       @SWG\Parameters(
	 *         @SWG\Parameter(
	 *           name="credentials", description="Data containing name-value pairs used for logging into the system.",
	 *           paramType="body", required="true", allowMultiple=false, dataType="Login"
	 *         )
	 *       ),
	 *       @SWG\ErrorResponses(
	 *          @SWG\ErrorResponse(code="400", reason="Bad Request - Request does not have a valid format, all required parameters, etc."),
	 *          @SWG\ErrorResponse(code="401", reason="Unauthorized Access - No currently valid session available."),
	 *          @SWG\ErrorResponse(code="500", reason="System Error - Specific reason is included in the error message.")
	 *       )
	 *     )
	 *   )
	 * )
	 *
	 *
	 * @param string $email
	 * @param string $password
	 *
	 * @return array
	 * @throws Exception
	 * @throws BadRequestException
	 * @throws UnauthorizedException
	 */
	public static function userLogin( $email, $password )
	{
		if ( empty( $email ) )
		{
			throw new BadRequestException( "Login request is missing required email." );
		}

		if ( empty( $password ) )
		{
			throw new BadRequestException( "Login request is missing required password." );
		}

		$_model = new LoginForm();
		$_model->username = $email;
		$_model->password = $password;
		$_model->setDrupalAuth( false );

		if ( !$_model->authenticate( 'password', 'authenticate' ) || !$_model->login() )
		{
			throw new UnauthorizedException( 'The credentials supplied do not match system records.' );
		}

		if ( null === ( $theUser = $_model->getIdentity()->getUser() ) )
		{
			// bad user object
			throw new Exception( "The user session contains no data.", ErrorCodes::INTERNAL_SERVER_ERROR );
		}

		if ( 'y' !== $theUser->confirm_code )
		{
			throw new Exception( "Login registration has not been confirmed." );
		}

		$isSysAdmin = $theUser->is_sys_admin;
		$result = static::generateSessionDataFromUser( $theUser->id, $theUser );

		// write back login datetime
		$theUser->update( array( 'last_login_date' => date( 'c' ) ) );

		static::$_userId = $theUser->id;

		// additional stuff for session - launchpad mainly
		return static::addSessionExtras( $result, $isSysAdmin, true );
	}

	/**
	 * @SWG\Api(
	 *   path="/user/session", description="Operations on a user's session.",
	 *   @SWG\Operations(
	 *     @SWG\Operation(
	 *       httpMethod="DELETE", summary="Logout and destroy the current user session.",
	 *       notes="Calling this deletes the current session and logs out the user.",
	 *       responseClass="Success", nickname="logout",
	 *       @SWG\ErrorResponses(
	 *          @SWG\ErrorResponse(code="401", reason="Unauthorized Access - No currently valid session available."),
	 *          @SWG\ErrorResponse(code="500", reason="System Error - Specific reason is included in the error message.")
	 *       )
	 *     )
	 *   )
	 * )
	 *
	 */
	public static function userLogout()
	{
		Pii::user()->logout();
	}

	/**
	 * @param      $user_id
	 * @param null $user
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function generateSessionDataFromUser( $user_id, $user = null )
	{
		if ( !isset( $user ) )
		{
			$user = User::model()->with( 'role.role_service_accesses', 'role.apps', 'role.services' )->findByPk( $user_id );
		}
		if ( null === $user )
		{
			throw new Exception( "The user with id $user_id does not exist in the system.", ErrorCodes::UNAUTHORIZED );
		}
		$email = $user->getAttribute( 'email' );
		if ( !$user->getAttribute( 'is_active' ) )
		{
			throw new Exception( "The user with email '$email' is not currently active.", ErrorCodes::FORBIDDEN );
		}

		$isSysAdmin = $user->getAttribute( 'is_sys_admin' );
		$defaultAppId = $user->getAttribute( 'default_app_id' );
		$fields = array( 'id', 'display_name', 'first_name', 'last_name', 'email', 'is_sys_admin', 'last_login_date' );
		$userInfo = $user->getAttributes( $fields );
		$data = $userInfo; // reply data
		$allowedApps = array();

		if ( !$isSysAdmin )
		{
			$theRole = $user->getRelated( 'role' );
			if ( !isset( $theRole ) )
			{
				throw new Exception( "The user '$email' has not been assigned a role.", ErrorCodes::FORBIDDEN );
			}
			if ( !$theRole->getAttribute( 'is_active' ) )
			{
				throw new Exception( "The role this user is assigned to is not currently active.", ErrorCodes::FORBIDDEN );
			}

			if ( !isset( $defaultAppId ) )
			{
				$defaultAppId = $theRole->getAttribute( 'default_app_id' );
			}
			$role = $theRole->attributes;
			$roleApps = array();
			/**
			 * @var App[] $theApps
			 */
			$theApps = $theRole->getRelated( 'apps' );
			if ( !empty( $theApps ) )
			{
				$appFields = array( 'id', 'api_name', 'is_active' );
				foreach ( $theApps as $app )
				{
					$roleApps[] = $app->getAttributes( $appFields );
					if ( $app->getAttribute( 'is_active' ) )
					{
						$allowedApps[] = $app;
					}
				}
			}
			$role['apps'] = $roleApps;
			$permsFields = array( 'service_id', 'component', 'access' );
			/**
			 * @var RoleServiceAccess[] $thePerms
			 * @var Service[] $theServices
			 */
			$thePerms = $theRole->getRelated( 'role_service_accesses' );
			$theServices = $theRole->getRelated( 'services' );
			$perms = array();
			foreach ( $thePerms as $perm )
			{
				$permServiceId = $perm->getAttribute( 'service_id' );
				$temp = $perm->getAttributes( $permsFields );
				foreach ( $theServices as $service )
				{
					if ( $permServiceId == $service->getAttribute( 'id' ) )
					{
						$temp['service'] = $service->getAttribute( 'api_name' );
					}
				}
				$perms[] = $temp;
			}
			$role['services'] = $perms;
			$userInfo['role'] = $role;
		}

		return array(
			'public'         => $userInfo,
			'data'           => $data,
			'allowed_apps'   => $allowedApps,
			'default_app_id' => $defaultAppId
		);
	}

	/**
	 * @param      $role_id
	 * @param null $role
	 *
	 * @throws Exception
	 *
	 * @return array
	 */
	public static function generateSessionDataFromRole( $role_id, $role = null )
	{
		if ( !isset( $role ) )
		{
			if ( empty( $role_id ) )
			{
				throw new Exception( "No valid role is assigned for guest users.", ErrorCodes::UNAUTHORIZED );
			}
			$role = Role::model()->with( 'role_service_accesses', 'apps', 'services' )->findByPk( $role_id );
		}
		if ( null === $role )
		{
			throw new Exception( "The role with id $role_id does not exist in the system.", ErrorCodes::UNAUTHORIZED );
		}
		$name = $role->getAttribute( 'name' );
		if ( !$role->getAttribute( 'is_active' ) )
		{
			throw new Exception( "The role '$name' is not currently active.", ErrorCodes::FORBIDDEN );
		}

		$userInfo = array();
		$data = array(); // reply data
		$allowedApps = array();

		$defaultAppId = $role->getAttribute( 'default_app_id' );
		$roleData = $role->attributes;
		/**
		 * @var App[] $theApps
		 */
		$theApps = $role->getRelated( 'apps' );
		$roleApps = array();
		if ( !empty( $theApps ) )
		{
			$appFields = array( 'id', 'api_name', 'is_active' );
			foreach ( $theApps as $app )
			{
				$roleApps[] = $app->getAttributes( $appFields );
				if ( $app->getAttribute( 'is_active' ) )
				{
					$allowedApps[] = $app;
				}
			}
		}
		$roleData['apps'] = $roleApps;
		$permsFields = array( 'service_id', 'component', 'access' );
		/**
		 * @var RoleServiceAccess[] $thePerms
		 * @var Service[] $theServices
		 */
		$thePerms = $role->getRelated( 'role_service_accesses' );
		$theServices = $role->getRelated( 'services' );
		$perms = array();
		foreach ( $thePerms as $perm )
		{
			$permServiceId = $perm->getAttribute( 'service_id' );
			$temp = $perm->getAttributes( $permsFields );
			foreach ( $theServices as $service )
			{
				if ( $permServiceId == $service->getAttribute( 'id' ) )
				{
					$temp['service'] = $service->getAttribute( 'api_name' );
				}
			}
			$perms[] = $temp;
		}
		$roleData['services'] = $perms;
		$userInfo['role'] = $roleData;

		return array(
			'public'         => $userInfo,
			'data'           => $data,
			'allowed_apps'   => $allowedApps,
			'default_app_id' => $defaultAppId
		);
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	public static function validateSession()
	{
		if ( !Pii::guest() && !Pii::getState( 'df_authenticated', false ) )
		{
			return Pii::user()->getId();
		}

		// helper for non-browser-managed sessions
		$_sessionId = Utilities::getArrayValue( 'HTTP_X_DREAMFACTORY_SESSION_TOKEN', $_SERVER, '' );
//		Log::debug('passed in session ' . $_sessionId);
		if ( !empty( $_sessionId ) )
		{
			session_write_close();
			session_id( $_sessionId );
			if ( session_start() )
			{
				if ( !Pii::guest() && false === Pii::getState( 'df_authenticated', false ) )
				{
					return Pii::user()->getId();
				}
			}
			else
			{
				Log::error( 'Failed to start session from header ' . $_sessionId );
			}
		}

		throw new Exception( "There is no valid session for the current request.", ErrorCodes::UNAUTHORIZED );
	}

	public static function isSystemAdmin()
	{
		static::_checkCache();

		$_public = Utilities::getArrayValue( 'public', static::$_cache, array() );

		return Utilities::boolval( Utilities::getArrayValue( 'is_sys_admin', $_public, false ) );
	}

	/**
	 * @param        $request
	 * @param        $service
	 * @param string $component
	 *
	 * @throws Exception
	 */
	public static function checkSessionPermission( $request, $service, $component = '' )
	{
		static::_checkCache();

		$_public = Utilities::getArrayValue( 'public', static::$_cache, array() );

		$admin = Utilities::getArrayValue( 'is_sys_admin', $_public, false );
		if ( $admin )
		{
			return; // no need to check role
		}
		$roleInfo = Utilities::getArrayValue( 'role', $_public, array() );
		if ( empty( $roleInfo ) )
		{
			// no role assigned, if not sys admin, denied service
			throw new Exception( "A valid user role or system administrator is required to access services.", ErrorCodes::FORBIDDEN );
		}

		// check if app allowed in role
		$appName = Utilities::getArrayValue( 'app_name', $GLOBALS, '' );
		if ( empty( $appName ) )
		{
			throw new Exception( "A valid application name is required to access services.", ErrorCodes::BAD_REQUEST );
		}

		$apps = Utilities::getArrayValue( 'apps', $roleInfo, null );
		if ( !is_array( $apps ) || empty( $apps ) )
		{
			throw new Exception( "Access to application '$appName' is not provisioned for this user's role.", ErrorCodes::FORBIDDEN );
		}

		$found = false;

		foreach ( $apps as $app )
		{
			$temp = Utilities::getArrayValue( 'api_name', $app );

			if ( 0 == strcasecmp( $appName, $temp ) )
			{
				$found = true;
				break;
			}
		}

		if ( !$found )
		{
			throw new Exception( "Access to application '$appName' is not provisioned for this user's role.", ErrorCodes::FORBIDDEN );
		}

		$services = Utilities::getArrayValue( 'services', $roleInfo, null );

		if ( !is_array( $services ) || empty( $services ) )
		{
			throw new Exception( "Access to service '$service' is not provisioned for this user's role.", ErrorCodes::FORBIDDEN );
		}

		$allAllowed = false;
		$allFound = false;
		$serviceAllowed = false;
		$serviceFound = false;

		foreach ( $services as $svcInfo )
		{
			$theService = Utilities::getArrayValue( 'service', $svcInfo );
			$theAccess = Utilities::getArrayValue( 'access', $svcInfo, '' );

			if ( 0 == strcasecmp( $service, $theService ) )
			{
				$theComponent = Utilities::getArrayValue( 'component', $svcInfo );
				if ( !empty( $component ) )
				{
					if ( 0 == strcasecmp( $component, $theComponent ) )
					{
						if ( !static::isAllowed( $request, $theAccess ) )
						{
							$msg = ucfirst( $request ) . " access to component '$component' of service '$service' ";
							$msg .= "is not allowed by this user's role.";
							throw new Exception( $msg, ErrorCodes::FORBIDDEN );
						}

						return; // component specific found and allowed, so bail
					}
					elseif ( empty( $theComponent ) || ( '*' == $theComponent ) )
					{
						$serviceAllowed = static::isAllowed( $request, $theAccess );
						$serviceFound = true;
					}
				}
				else
				{
					if ( empty( $theComponent ) || ( '*' == $theComponent ) )
					{
						if ( !static::isAllowed( $request, $theAccess ) )
						{
							$msg = ucfirst( $request ) . " access to service '$service' ";
							$msg .= "is not allowed by this user's role.";
							throw new Exception( $msg, ErrorCodes::FORBIDDEN );
						}

						return; // service specific found and allowed, so bail
					}
				}
			}
			elseif ( empty( $theService ) || ( '*' == $theService ) )
			{
				$allAllowed = static::isAllowed( $request, $theAccess );
				$allFound = true;
			}
		}

		if ( $serviceFound )
		{
			if ( $serviceAllowed )
			{
				return; // service found and allowed, so bail
			}
		}
		elseif ( $allFound )
		{
			if ( $allAllowed )
			{
				return; // all services found and allowed, so bail
			}
		}

		$msg = ucfirst( $request ) . " access to ";
		if ( !empty( $component ) )
		{
			$msg .= "component '$component' of ";
		}

		$msg .= "service '$service' is not allowed by this user's role.";

		throw new Exception( $msg, ErrorCodes::FORBIDDEN );
	}

	/**
	 * @param $request
	 * @param $access
	 *
	 * @return bool
	 */
	protected static function isAllowed( $request, $access )
	{
		switch ( $request )
		{
			case 'read':
				switch ( $access )
				{
					case PermissionTypes::READ_ONLY:
					case PermissionTypes::READ_WRITE:
					case PermissionTypes::FULL_ACCESS:
						return true;
				}
				break;

			case 'create':
			case 'update':
				switch ( $access )
				{
					case PermissionTypes::WRITE_ONLY:
					case PermissionTypes::READ_WRITE:
					case PermissionTypes::FULL_ACCESS:
						return true;
				}
				break;

			case 'delete':
				switch ( $access )
				{
					case PermissionTypes::FULL_ACCESS:
						return true;
				}
				break;
		}

		return false;
	}

	/**
	 * @param $userId
	 */
	public static function setCurrentUserId( $userId )
	{
		static::$_userId = $userId;

		return $userId;
	}

	/**
	 * @return int|null
	 */
	public static function getCurrentUserId()
	{
		if ( !empty( static::$_userId ) )
		{
			return static::$_userId;
		}

		if ( !Pii::guest() && false === Pii::getState( 'df_authenticated', false ) )
		{
			return static::$_userId = Pii::user()->getId();
		}

		return null;
	}

	/**
	 * @throws Exception
	 */
	protected static function _checkCache()
	{
		if ( empty( static::$_cache ) )
		{
			try
			{
				$userId = static::validateSession();
				static::$_cache = static::generateSessionDataFromUser( $userId );
			}
			catch ( Exception $ex )
			{
				// special case for possible guest user
				$theConfig = Config::model()->with(
					'guest_role.role_service_accesses',
					'guest_role.apps',
					'guest_role.services'
				)->find();

				if ( !empty( $theConfig ) )
				{
					if ( Utilities::boolval( $theConfig->allow_guest_user ) )
					{
						static::$_cache = static::generateSessionDataFromRole( null, $theConfig->getRelated( 'guest_role' ) );
						return;
					}
				}

				// otherwise throw original exception
				throw $ex;
			}
		}
	}

	/**
	 * @param array $session
	 * @param bool  $is_sys_admin
	 * @param bool  $add_apps
	 *
	 * @return array
	 */
	public static function addSessionExtras( $session, $is_sys_admin = false, $add_apps = false )
	{
		$data = Utilities::getArrayValue( 'data', $session, array() );
		$userId = Utilities::getArrayValue( 'id', $data, '' );
		$timestamp = time();
		$ticket = Utilities::encryptCreds( "$userId,$timestamp", "gorilla" );
		$data['ticket'] = $ticket;
		$data['ticket_expiry'] = time() + ( 5 * 60 );
		$data['session_id'] = session_id();

		if ( $add_apps )
		{
			$appFields = 'id,api_name,name,description,url,is_url_external,requires_fullscreen,allow_fullscreen_toggle,toggle_location';
			/**
			 * @var App[] $theApps
			 */
			$theApps = Utilities::getArrayValue( 'allowed_apps', $session, array() );
			if ( $is_sys_admin )
			{
				$theApps = App::model()->findAll( 'is_active = :ia', array( ':ia' => 1 ) );
			}
			/**
			 * @var AppGroup[] $theGroups
			 */
			$theGroups = AppGroup::model()->with( 'apps' )->findAll();
			$appGroups = array();
			$noGroupApps = array();
			$defaultAppId = Utilities::getArrayValue( 'default_app_id', $session, null );
			foreach ( $theApps as $app )
			{
				$appId = $app->id;
				$tempGroups = $app->getRelated( 'app_groups' );
				$appData = $app->getAttributes( explode( ',', $appFields ) );
				$appData['is_default'] = ( $defaultAppId === $appId );
				$found = false;
				foreach ( $theGroups as $g_key => $group )
				{
					$groupId = $group->id;
					$groupData = ( isset( $appGroups[$g_key] ) ) ? $appGroups[$g_key] : $group->getAttributes( array( 'id', 'name', 'description' ) );
					foreach ( $tempGroups as $tempGroup )
					{
						if ( $tempGroup->id === $groupId )
						{
							$found = true;
							$temp = Utilities::getArrayValue( 'apps', $groupData, array() );
							$temp[] = $appData;
							$groupData['apps'] = $temp;
						}
					}
					$appGroups[$g_key] = $groupData;
				}
				if ( !$found )
				{
					$noGroupApps[] = $appData;
				}
			}
			// clean out any empty groups
			foreach ( $appGroups as $g_key => $group )
			{
				if ( !isset( $group['apps'] ) )
				{
					unset( $appGroups[$g_key] );
				}
			}
			$data['app_groups'] = array_values( $appGroups ); // reset indexing
			$data['no_group_apps'] = $noGroupApps;
		}

		return $data;
	}
}