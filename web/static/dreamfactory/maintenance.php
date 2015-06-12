<!DOCTYPE html>
<html lang="en">
<head>
    <title>DreamFactory Services Platform&trade;</title>
    <meta name="page-route" content="web/index" />

    <meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, user-scalable=yes, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="author" content="DreamFactory Software, Inc.">
    <meta name="language" content="en" />
    <link rel="shortcut icon" href="/img/df_logo_factory-32x32.png" />

    <!-- Bootstrap 3 CSS -->
    <link rel="stylesheet" href="/static/bootstrap/3.3.4/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="/static/font-awesome/4.3.0/css/font-awesome.min.css">

    <!-- DSP UI Styles & Code -->
    <link rel="stylesheet" href="/css/dsp.main.css">
    <link rel="stylesheet" href="/css/maintenance.css">

    <script src="/static/jquery/2.1.4/jquery.min.js"></script>
    <style>
        html, body {
            min-width: 800px;
        }

        .jumbotron {
            min-width:   635px;
            width:       100%;
            padding-top: 16px;
        }

        .jumbotron > h1 {
            margin-bottom: 24px;
        }

        #last-checked {
            display:     inline-block;
            color:       rgba(60, 118, 61, 1.0);
            font-size:   17px;
            margin-left: 10px;
        }
    </style>
</head>
<body class="maintenance-page">

<div class="navbar navbar-inverse navbar-fixed-top" role="navigation">
    <div class="container-fluid">
        <div class="navbar-header">
            <div class="pull-left df-logo"><a href="/"><img src="/img/logo-navbar-194x42.png"></a></div>
        </div>

        <div id="navbar-container"></div>
    </div>
</div>

<div class="container-fluid maintenance">
    <div class="jumbotron">
        <h1><i class="fa fa-fw fa-exclamation-circle text-danger"></i>Platform Offline
            <small class="pull-right"></small>
        </h1>

        <p>The system is currently undergoing maintenance.</p>

        <p>If you think you've received this page in error, please check with your system administrator for more information.</p>

        <p style="margin-bottom: 0;">
            <button class="btn btn-warning btn-refresh"><i class="fa fa-fw fa-refresh"></i>Refresh</button>
            <span id="last-checked"></span>
        </p>
    </div>
</div>

<div id="footer">
    <div class="container-fluid">
        <div class="pull-left dsp-footer-copyright">
            <p class="footer-text">&copy; <a target="_blank" href="https://www.dreamfactory.com">DreamFactory Software, Inc.</a> 2012-
                <?php echo date( 'Y' ); ?>. All Rights Reserved.
            </p>
        </div>
        <div class="pull-right dsp-footer-version">
            <p class="footer-text">
                <a href="https://github.com/dreamfactorysoftware/dsp-core/"
                   target="_blank"><?php if ( defined( DSP_VERSION ) )
                    {
                        echo 'v' . DSP_VERSION;
                    }
                    ?>
                </a>
            </p>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var _fromUri = '<?php echo filter_input(INPUT_GET,'from',FILTER_SANITIZE_STRING);?>';
    var _page = window.top.location.href;

    /**
     * Refreshes the page
     * @private
     */
    if (_fromUri.length) {
        //  Start check in 10s, increment 5s each time up to one minute
        var _checkInterval = 10000;

        //  Refresh the entire page
        var _refreshPage = function() {
            window.top.location.href = _fromUri;
        };

        //  Refresh the last update time
        var _refreshTime = function() {
            var _date = new Date();

            //  Reset the color
            $('#last-checked').css({
                color: 'rgba(60, 118, 61, 1.0)'
            }).animate({opacity: 1.0}, 0, function() {
                $(this).html('last checked at ' + _date.toString()).animate({
                    opacity: 0.40
                }, 5000, function() {
                    _checkInterval += 5000;

                    if (_checkInterval > 60000) {
                        _checkInterval = 60000;
                    }

                    //  Check again in a minute
                    window.setTimeout(_checkSite, _checkInterval);
                });
            });
        };

        //  Do the site check
        var _checkSite = function() {
            var _last = window.top.location.href;

            $.ajax({
                method:      'GET',
                url:         '/',
                processData: false
            }).complete(function(xhr) {
                switch (xhr.status) {
                    case 500:   //  ISE
                    case 404:   //  Not found
                    case 200:   //  OK
                        if (window.top.location.href != _last) {
                            _refreshPage();
                        } else {
                            _refreshTime();
                        }
                        break;

                    default:
                        _refreshPage();
                        break;
                }
            });
        };

        $('.btn-refresh').on('click', function(e) {
            e.preventDefault();
            window.top.location.href = _fromUri;
        });

        //  Kick it all off
        _checkSite();
    }
});
</script>
</body>
</html>