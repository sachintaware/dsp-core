<?php
use \CloudServicesPlatform\ServiceHandlers\ServiceHandler;
use CloudServicesPlatform\Utilities\Utilities;
use CloudServicesPlatform\Storage\Database\PdoSqlDbSvc;

class SiteController extends Controller
{
	/**
	 * Declares class-based actions.
	 */
	public function actions()
	{
		return array(
			// captcha action renders the CAPTCHA image displayed on the contact page
			'captcha' => array(
				'class'     => 'CCaptchaAction',
				'backColor' => 0xFFFFFF,
			),
			// page action renders "static" pages stored under 'protected/views/site/pages'
			// They can be accessed via: index.php?r=site/page&view=FileName
			'page'    => array(
				'class' => 'CViewAction',
			),
		);
	}

	/**
	 * This is the default 'index' action that is invoked
	 * when an action is not explicitly requested by users.
	 */
	public function actionIndex()
	{
		try
		{
			if ( 'ready' === $this->getSystemState() )
			{
                $svc = ServiceHandler::getInstance();
				$app = $svc->getServiceObject('App');
				// check if loaded in blob storage as app
				if ( $app->appExists( 'LaunchPad' ) )
				{
					header( "Location: ./app/LaunchPad/index.html" );
					exit;
				}
				// otherwise use local copy
				header( "Location: ./public/launchpad/index.html" );
                // renders the view file 'protected/views/site/index.php'
                // using the default layout 'protected/views/layouts/main.php'
                //$this->render( 'index' );
                Yii::app()->end();
			}
			else
			{
				$this->actionInit();
                Yii::app()->end();
			}
		}
		catch ( Exception $ex )
		{
			die( $ex->getMessage() );
		}
	}

	/**
	 * This is the action to handle external exceptions.
	 */
	public function actionError()
	{
        $error = Yii::app()->errorHandler->error;
        if ( $error )
		{
			if ( Yii::app()->request->isAjaxRequest )
			{
				echo $error['message'];
			}
			else
			{
				$this->render( 'error', $error );
			}
		}
	}

	/**
	 * Displays the contact page
	 */
	public function actionContact()
	{
		$model = new ContactForm;
		if ( isset( $_POST['ContactForm'] ) )
		{
			$model->attributes = $_POST['ContactForm'];
			if ( $model->validate() )
			{
				$name = '=?UTF-8?B?' . base64_encode( $model->name ) . '?=';
				$subject = '=?UTF-8?B?' . base64_encode( $model->subject ) . '?=';
				$headers = "From: $name <{$model->email}>\r\n" .
						   "Reply-To: {$model->email}\r\n" .
						   "MIME-Version: 1.0\r\n" .
						   "Content-type: text/plain; charset=UTF-8";

				mail( Yii::app()->params['adminEmail'], $subject, $model->body, $headers );
				Yii::app()->user->setFlash( 'contact', 'Thank you for contacting us. We will respond to you as soon as possible.' );
				$this->refresh();
			}
		}
		$this->render( 'contact', array( 'model' => $model ) );
	}

	/**
	 * Displays the login page
	 */
	public function actionLogin()
	{
		$model = new LoginForm;

		// if it is ajax validation request
		if ( isset( $_POST['ajax'] ) && $_POST['ajax'] === 'login-form' )
		{
			echo CActiveForm::validate( $model );
			Yii::app()->end();
		}

		// collect user input data
		if ( isset( $_POST['LoginForm'] ) )
		{
			$model->attributes = $_POST['LoginForm'];
			// validate user input and redirect to the previous page if valid
			if ( $model->validate() && $model->login() )
			{
				$this->redirect( Yii::app()->user->returnUrl );
			}
		}
		// display the login form
		$this->render( 'login', array( 'model' => $model ) );
	}

	/**
	 * Logs out the current user and redirect to homepage.
	 */
	public function actionLogout()
	{
		Yii::app()->user->logout();
		$this->redirect( Yii::app()->homeUrl );
	}

    /**
   	 * Displays the system init page
   	 */
   	public function actionInit()
   	{
   		$model = new InitForm;

   		// collect user input data
   		if ( isset( $_POST['InitForm'] ) )
   		{
   			$model->attributes = $_POST['InitForm'];
   			// validate user input, configure the system and redirect to the previous page
   			if ( $model->validate())
   			{
                $this->configure($model->attributes);
   				$this->redirect( Yii::app()->user->returnUrl );
   			}
            $this->refresh();
   		}
   		// display the init form
   		$this->render( 'init', array( 'model' => $model ) );
   	}

    /**
   	 * Displays the admin page
   	 */
   	public function actionAdmin()
   	{
   		$this->render( 'admin' );
   	}

    /**
   	 * Displays the environment page
   	 */
   	public function actionEnvironment()
   	{
   		$this->render( 'environment' );
   	}

    /**
   	 * Determines the current state of the system
   	 */
    public function getSystemState()
    {
        try {
            $tables = Yii::app()->db->schema->getTableNames();
            if (!in_array('app', $tables) ||
                !in_array('app_group', $tables) ||
                !in_array('label', $tables) ||
                !in_array('role', $tables) ||
                !in_array('role_service_access', $tables) ||
                !in_array('service', $tables) ||
                !in_array('session', $tables) ||
                !in_array('user', $tables)) {
                return 'init required';
            }
            $db = new PdoSqlDbSvc();
            $result = $db->retrieveSqlRecordsByFilter('service', 'name');
            unset($result['total']);
            if (count($result) < 1) {
                return 'init required';
            }
            $result = $db->retrieveSqlRecordsByFilter('app', 'name');
            unset($result['total']);
            if (count($result) < 1) {
                return 'init required';
            }

            $result = $db->retrieveSqlRecordsByFilter('user', 'username', "is_sys_admin = 1", 1);
            unset($result['total']);
            if (count($result) < 1) {
                return 'admin required';
            }
            return 'ready';
        }
        catch (\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * Configures the system.
     *
     * @param array $data
     * @throws Exception
     * @return boolean whether configuration is successful
     */
   	public function configure($data = array())
   	{
        try {
            $contents = file_get_contents(Yii::app()->basePath.'/data/system_schema.json');
            if (empty($contents)) {
                throw new \Exception("Empty or no system schema file found.");
            }
            $contents = Utilities::jsonToArray($contents);
            // create system tables
            $tables = Utilities::getArrayValue('table', $contents);
            if (empty($tables)) {
                throw new \Exception("No default system schema found.");
            }
            $db = new PdoSqlDbSvc();
            $result = $db->createTables($tables, true, true);
            // setup session stored procedure
            $query = 'SELECT ROUTINE_NAME FROM INFORMATION_SCHEMA.ROUTINES
                      WHERE ROUTINE_TYPE="PROCEDURE"
                          AND ROUTINE_SCHEMA="dreamfactory"
                          AND ROUTINE_NAME="UpdateOrInsertSession";';
            $result = $db->singleSqlQuery($query);
            if ((empty($result)) || !isset($result[0]['ROUTINE_NAME'])) {
                switch ($db->getDriverType()) {
                case Utilities::DRV_SQLSRV:
                    $query =
                        'CREATE PROCEDURE dbo.UpdateOrInsertSession
                           @id nvarchar(32),
                           @start_time int,
                           @data nvarchar(4000)
                        AS
                        BEGIN
                            IF EXISTS (SELECT id FROM session WHERE id = @id)
                                BEGIN
                                    UPDATE session
                                    SET  data = @data, start_time = @start_time
                                    WHERE id = @id
                                END
                            ELSE
                                BEGIN
                                    INSERT INTO session (id, start_time, data)
                                    VALUES ( @id, @start_time, @data )
                                END
                        END';
                    break;
                case Utilities::DRV_MYSQL:
                default:
                    $query =
                        'CREATE PROCEDURE `UpdateOrInsertSession`(IN the_id nvarchar(32),
                                                                  IN the_start int,
                                                                  IN the_data nvarchar(4000))
                        BEGIN
                            IF EXISTS (SELECT `id` FROM `session` WHERE `id` = the_id) THEN
                                UPDATE session
                                SET  `data` = the_data, `start_time` = the_start
                                WHERE `id` = the_id;
                            ELSE
                                INSERT INTO session (`id`, `start_time`, `data`)
                                VALUES ( the_id, the_start, the_data );
                            END IF;
                        END';
                    break;
                }
                $db->singleSqlExecute($query);
            }
            // refresh the schema that we just added
            Yii::app()->db->schema->refresh();

            // create and login first admin user
            // fill out the user fields for creation
            $username = Utilities::getArrayValue('username', $data);
            $firstName = Utilities::getArrayValue('firstName', $data);
            $lastName = Utilities::getArrayValue('lastName', $data);
            $fields = array('username' => $username,
                            'email' => Utilities::getArrayValue('email', $data),
                            'password' => md5(Utilities::getArrayValue('password', $data)),
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'full_name' => $firstName . ' ' . $lastName,
                            'is_active' => true,
                            'is_sys_admin' => true,
                            'confirm_code' => 'y'
            );
            $result = $db->retrieveSqlRecordsByFilter('user', 'id', "username = '$username'", 1);
            unset($result['total']);
            if (count($result) > 0) {
                throw new \Exception("A user already exists with the username '$username'.");
            }
            $result = $db->createSqlRecord('user', $fields);
            if (!isset($result[0])) {
                error_log(print_r($result, true));
                throw new \Exception("Failed to create user.");
            }
            $userId = Utilities::getArrayValue('id', $result[0]);
            if (empty($userId)) {
                error_log(print_r($result[0], true));
                throw new \Exception("Failed to create user.");
            }
            Utilities::setCurrentUserId($userId);

            // init system tables with records
            $contents = file_get_contents(Yii::app()->basePath.'/data/system_data.json');
            if (empty($contents)) {
                throw new \Exception("Empty or no system data file found.");
            }
            $contents = Utilities::jsonToArray($contents);
            $result = $db->retrieveSqlRecordsByFilter('service', 'id', '', 1);
            unset($result['total']);
            if (empty($result)) {
                $services = Utilities::getArrayValue('service', $contents);
                if (empty($services)) {
                    error_log(print_r($contents, true));
                    throw new \Exception("No default system services found.");
                }
                $db->createSqlRecords('service', $services, true);
            }
            $result = $db->retrieveSqlRecordsByFilter('app', 'id', '', 1);
            unset($result['total']);
            if (empty($result)) {
                $apps = Utilities::getArrayValue('app', $contents);
                if (empty($apps)) {
                    error_log(print_r($contents, true));
                    throw new \Exception("No default system apps found.");
                }
                $db->createSqlRecords('app', $apps, true);
            }

            return true;
        }
        catch (\Exception $ex) {
            throw $ex;
        }
        return false;
   	}

}