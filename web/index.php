<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
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
use DreamFactory\Library\Utility\Includer;
use DreamFactory\Platform\Utility\Enterprise;
use DreamFactory\Platform\Utility\Fabric;
use DreamFactory\Platform\Yii\Components\PlatformConsoleApplication;
use DreamFactory\Platform\Yii\Components\PlatformWebApplication;
use DreamFactory\Yii\Utility\Pii;

/** index.php -- Main entry point/bootstrap for all processes **/

//******************************************************************************
//* Constants
//******************************************************************************

/**
 * @type bool Global debug flag: If true, your logs will grow large and your performance will suffer, but fruitful information will be gathered.
 */
const DSP_DEBUG = true;
/**
 * @type bool Global PHP-ERROR flag: If true, PHP-ERROR will be utilized if available. See https://github.com/JosephLenton/PHP-Error for more info.
 */
const DSP_DEBUG_PHP_ERROR = true;
/**
 * @type string The redirect to the maintenance page
 */
const MAINTENANCE_URI = '/static/dreamfactory/maintenance.php';

//******************************************************************************
//* Bootstrap
//******************************************************************************

if ( !function_exists( '__yii_bootstrap' ) )
{
    /**
     * @return PlatformWebApplication|PlatformConsoleApplication
     */
    function __yii_bootstrap()
    {
        //  Determine our app class
        $_class = 'DreamFactory\\Platform\\Yii\\Components\\Platform' . ( 'cli' == PHP_SAPI ? 'Console' : 'Web' ) . 'Application';

        //  Load up composer...
        $_autoloader = require_once( __DIR__ . '/../vendor/autoload.php' );

        //	Load constants...
        Includer::includeIfExists( __DIR__ . '/../config/constants.config.php', true, false );

        /**
         * Debug-level output based on constant value above
         * For production mode, you'll want to set the above constants to FALSE
         * Get this turned on before anything is loaded
         */
        if ( DSP_DEBUG )
        {
            ini_set( 'display_errors', 1 );
            defined( 'YII_DEBUG' ) or define( 'YII_DEBUG', true );
        }

        //  Load up Yii if it's not been already
        if ( !class_exists( '\\Yii', false ) )
        {
            require_once __DIR__ . '/../vendor/dreamfactory/yii/framework/yiilite.php';
        }

        //  php-error utility
        if ( DSP_DEBUG_PHP_ERROR && function_exists( 'reportErrors' ) )
        {
            reportErrors();
        }

        if ( is_file( Fabric::MAINTENANCE_MARKER ) || is_file( Enterprise::MAINTENANCE_MARKER ) )
        {
            if ( isset( $_SERVER, $_SERVER['REQUEST_URI'] ) && MAINTENANCE_URI != $_SERVER['REQUEST_URI'] )
            {
                header( 'Location: ' . MAINTENANCE_URI . '?from=' . urlencode( $_SERVER['REQUEST_URI'] ) );
                die();
            }
        }

        //  Create the application and run. This does not return until the request is complete.
        return Pii::run( __DIR__, $_autoloader, $_class );
    }
}

//  Kick if off
return __yii_bootstrap();
