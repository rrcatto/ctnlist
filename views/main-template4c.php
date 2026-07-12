<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="all">
    <meta name="description" content="email marketing software">
    <meta name="author" content="rc-webs">
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <title><?php echo "{$ListName} {$title}"; ?></title>

    <!-- Latest compiled and minified CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">

    <!-- Custom styles for this template -->
    <link rel="stylesheet" type="text/css" href="https://fonts.googleapis.com/css?family=Noto+Sans|Open+Sans|Roboto|Raleway">
    <link rel="stylesheet" type="text/css" href="https://financial-courses.co.za/css/2020.01.27-theme.css">

    <style>
      .carousel-item {
        height: 89vh;
        min-height: 350px;
        background: no-repeat center center scroll;
        -webkit-background-size: cover;
        -moz-background-size: cover;
        -o-background-size: cover;
        background-size: cover;
      }

      .xbootstrap {
        color: white;
        /* background-color: #2d2d2d; */
        /* letter-spacing: .1em;*/
        /* text-shadow: -1px -1px 1px #111, 2px 2px 1px #363636; */
        text-shadow: -1px 0 1px black, 0 1px 1px black, 1px 0 1px black, 0 -1px 1px black;
      }

      .wrapper{
          width: 1140px;
          margin: 0 auto;
      }

      html {
        position: relative;
        min-height: 100%;
      }

      body {
        /* background-color: #FFFFCC; */
        background-color: #E2F0FF;
        background-color: #DBDAEA;
        background-color: #3066BE;
        background-color: #090C9B;
        background-color: #FADDC7;
        background-color: #B4C5E4;
        margin-bottom: 300px;
      }

      #footer {
        position: absolute;
        width: 100%;
        bottom: 0;
        height: 300px;
      }

      #footer a {
        /* color: #fff; */
        text-decoration:none;
      }

      #footer a:hover, #footer a:focus {
        /* color: #aaa; */
        text-decoration: underline;
        border-bottom:1px dotted #999;
      }

      #footer hr {
        height: 2px;
        background-color: #fff;
        border: none;
      }
    </style>

    <!-- Link to CKeditor javascript routines -->
    <script src="//cdn.ckeditor.com/4.15.1/full/ckeditor.js"></script>
    <script src="https://kit.fontawesome.com/5385ffda53.js" crossorigin="anonymous"></script>

  </head>

  <body>
    <?php include_once("analyticstracking.php") ?>
    <div class="g-color-primary text-center g-py-60" style="height: 50px; ">
       <p class="g-font-weight-700 g-font-size-20 text-uppercase">{{@Organisation}}</p>
   </div>


      <nav class="navbar navbar-expand-md navbar-dark sticky-top" style="background-color: #090C9B" role="navigation">
        <a class="navbar-brand" href="/"><?php echo $ListName; ?></a>
        <button class="navbar-toggler ml-auto " type="button" data-toggle="collapse" data-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="navbar-collapse collapse" id="navbarCollapse">
          <ul class="navbar-nav m-1 p-1 mr-auto">
            <li class="nav-item"><a class="nav-link" href="<?php echo $BaseURL; ?>"><i class="fa fa-home" aria-hidden="true"></i> Home</a></li>
            <?php
              if ($uadmin == 1) {
            ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="fa fa-key" aria-hidden="true"></i> Admin</a>
              <div class="dropdown-menu bg-success" aria-labelledby="navbarDropdownMenuLink">
                <a class="dropdown-item" href="<?php echo $BaseURL . 'templates'; ?>"><i class="fa fa-pencil-square" aria-hidden="true"></i> Templates</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'template'; ?>"><i class="fa fa-file-text" aria-hidden="true"></i> New Template</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'messages'; ?>"><i class="fa fa-pencil" aria-hidden="true"></i> Messages</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'message'; ?>"><i class="fa fa-file-text-o" aria-hidden="true"></i> New Message</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'queue'; ?>"><i class="fa fa-th-list" aria-hidden="true"></i> Queue</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'subscribers'; ?>"><i class="fa fa-users" aria-hidden="true"></i> Subscribers</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'sendlog'; ?>"><i class="fa fa-clipboard" aria-hidden="true"></i> Send Log</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'advanced-queue'; ?>"><i class="fa fa-arrows-alt" aria-hidden="true"></i> Queue Multiple Messages</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'bulk-subscribe'; ?>"><i class="fa fa-user-plus" aria-hidden="true"></i> Bulk Subscribe</a>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'bulk-unsubscribe'; ?>"><i class="fa fa-user-times" aria-hidden="true"></i> Bulk Unsubscribe</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" target="_blank" href="<?php echo $BaseURL . 'processqueue'; ?>"><i class="fa fa-cogs" aria-hidden="true"></i> Start Send ALL in Queue</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="<?php echo $BaseURL . 'stop-send'; ?>"><i class="fa fa-hand-paper-o" aria-hidden="true"></i> Stop Sending Queue</a>
              </div>
            </li>
            <?php
              }
            ?>
            <li class="nav-item"><a class="nav-link" href="<?php echo $BaseURL . 'subscribe'; ?>"><i class="fas fa-clipboard"></i> Subscribe</a></li>
            <?php
              if ($archive == 1) {
                echo "<li class=\"nav-item\"><a class=\"nav-link\" href=\"{$BaseURL}archives\"><i class=\"fa fa-archive\" aria-hidden=\"true\"></i> Archives</a></li>";
              }
            ?>
            <li class="nav-item"><a class="nav-link" href="<?php echo $BaseURL . 'contact-form'; ?>"><i class="fa fa-phone" aria-hidden="true"></i> Contact Form</a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo $BaseURL . 'privacy'; ?>"><i class="fa fa-eye" aria-hidden="true"></i> Privacy Policy</a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo $BaseURL . 'store'; ?>"><i class="fas fa-shopping-cart"></i> Store: Purchase Courses Online</a></li>
          <?php
            if ($uloggedin) {
              echo "<li class=\"nav-item dropdown\"><a class=\"nav-link dropdown-toggle\" href=\"#\" id=\"profileDropdownMenu\" data-toggle=\"dropdown\" aria-haspopup=\"true\" aria-expanded=\"false\"><i class=\"fa fa-user-circle-o\" aria-hidden=\"true\"></i> {$ufname} {$ulname}</a><div class=\"dropdown-menu bg-success\" aria-labelledby=\"profileDropdownMenu\">";
              echo "<a class=\"dropdown-item\" href=\"{$BaseURL}profile\"><i class=\"fa fa-user-o\" aria-hidden=\"true\"></i> My Profile</a>";
              echo "<a class=\"dropdown-item\" href=\"{$BaseURL}edit-profile\"><i class=\"fa fa-pencil\" aria-hidden=\"true\"></i> Edit Profile</a>";
              echo "<a class=\"dropdown-item\" href=\"{$BaseURL}logout\"><i class=\"fa fa-sign-out\" aria-hidden=\"true\"></i> Logout</a></div></li>";
            } else {
              echo "<li class=\"nav-item\"><a class=\"nav-link\" href=\"{$BaseURL}login\"><i class=\"fa fa-sign-in\" aria-hidden=\"true\"></i> login</a></li>";
            }
          ?>
          </ul>
        </div><!--/.navbar-collapse -->
      </nav>

    <header>
      <div class="d-flex p-2 mr-auto" style="background-color: #090C9B">
        <?php
          if ($uadmin > 0) {
            echo "<div class=\"btn-toolbar\" role=\"toolbar\" aria-label=\"button bar\">";
            echo "<div class=\"btn-group my-2 mr-2\" role=\"group\" aria-label=\"message buttons\">";
            echo "<a href=\"{$BaseURL}messages\" class=\"btn btn-info btn-sm\">Messages</a>";
            echo "<a href=\"{$BaseURL}message\" class=\"btn btn-info btn-sm\">New Message</a>";
            echo "<a href=\"{$BaseURL}templates\" class=\"btn btn-success btn-sm\">Templates</a>";
            echo "<a href=\"{$BaseURL}template\" class=\"btn btn-success btn-sm\">New Template</a>";
            echo "</div>";

            echo "<div class=\"btn-group my-2 mr-2\" role=\"group\" aria-label=\"queue buttons\">";
            echo "<a href=\"{$BaseURL}queue\" class=\"btn btn-primary btn-sm\">Queued <span class=\"badge badge-secondary\">{$qcount}</span></a>";
            echo "<a href=\"{$BaseURL}advanced-queue\" class=\"btn btn-primary btn-sm\">Queue Multiple Messages</a>";
            echo "<a href=\"{$BaseURL}processqueue\" target=\"_blank\" class=\"btn btn-success btn-sm\">Start Send</a>";
            echo "<a href=\"{$BaseURL}stop-send\" class=\"btn btn-warning btn-sm\">Stop Send</a>";
            echo "</div> ";

            echo "<div class=\"btn-group my-2 mr-2\" role=\"group\" aria-label=\"stats buttons\">";
            echo "<a href=\"{$BaseURL}sendlog\" class=\"btn btn-success btn-sm\">Sendlog <span class=\"badge badge-secondary\">{$slcount}</span></a>";
            echo "</div>";

            echo "<div class=\"btn-group my-2 mr-2\" role=\"group\" aria-label=\"subscriber buttons\">";
            echo "<a href=\"{$BaseURL}subscribers\" class=\"btn btn-warning text-white btn-sm\">Subscribers <span class=\"badge badge-secondary\">{$numsubscribers}</span></a>";
            echo "<a href=\"{$BaseURL}activesubscribers\" class=\"btn btn-warning btn-sm\">Active <span class=\"badge badge-secondary\">{$activereaders}</span></a>";
            echo "</div>";

            echo "<div class=\"btn-group my-2 mr-2\" role=\"group\" aria-label=\"bulk subscriber buttons\">";
            echo "<a href=\"{$BaseURL}bulk-subscribe\" class=\"btn btn-info text-white btn-sm\">Bulk Subscribe</a>";
            echo "<a href=\"{$BaseURL}bulk-unsubscribe\" class=\"btn btn-success btn-sm\">Bulk Unsubscribe</a>";
            echo "</div>";
            echo "</div>";
          }
        ?>
      </div>

    <?php
      if ($PATH == '/') {
    ?>
      <div id="carouselExampleIndicators" class="carousel slide" data-ride="carousel">
        <ol class="carousel-indicators">
          <li data-target="#carouselExampleIndicators" data-slide-to="0" class="active"></li>
          <li data-target="#carouselExampleIndicators" data-slide-to="1"></li>
          <li data-target="#carouselExampleIndicators" data-slide-to="2"></li>
        </ol>
        <div class="carousel-inner" role="listbox">
          <!-- Slide One - Set the background image for this slide in the line below -->
          <div class="carousel-item active" style="background-image: url('https://images.unsplash.com/photo-1497366412874-3415097a27e7?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=crop&w=2301&q=80')">
            <h1 class="display-1 xbootstrap"><center><?php echo "{$Organisation}"; ?></center></h1>
            <div class="carousel-caption d-none d-md-block">
              <h2 class="display-3 xbootstrap">Getting into the office</h2>
              <p class="lead xbootstrap">You arrive at your workplace prepared</p>
            </div>
          </div>
          <!-- Slide Two - Set the background image for this slide in the line below -->
          <div class="carousel-item" style="background-image: url('https://images.unsplash.com/photo-1517502884422-41eaead166d4?ixlib=rb-1.2.1&auto=format&fit=crop&w=3925&q=80')">
            <h1 class="display-1 xbootstrap"><center><?php echo "{$Organisation}"; ?></center></h1>
            <div class="carousel-caption d-none d-md-block">
              <h2 class="display-3 xbootstrap">Arrive Early</h2>
              <p class="lead xbootstrap">First to start work, last to leave</p>
            </div>
          </div>
          <!-- Slide Three - Set the background image for this slide in the line below -->
          <div class="carousel-item" style="background-image: url('https://images.unsplash.com/photo-1431540015161-0bf868a2d407?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=crop&w=2550&q=80')">
            <h1 class="display-1 xbootstrap"><center><?php echo "{$Organisation}"; ?></center></h1>
            <div class="carousel-caption d-none d-md-block">
              <h2 class="display-3 xbootstrap">You're Prepared for Anything</h2>
              <p class="lead xbootstrap">You know how to get things done, nothing fazes you</p>
            </div>
          </div>
        </div>
        <a class="carousel-control-prev" href="#carouselExampleIndicators" role="button" data-slide="prev">
              <span class="carousel-control-prev-icon" aria-hidden="true"></span>
              <span class="sr-only">Previous</span>
            </a>
        <a class="carousel-control-next" href="#carouselExampleIndicators" role="button" data-slide="next">
              <span class="carousel-control-next-icon" aria-hidden="true"></span>
              <span class="sr-only">Next</span>
            </a>
      </div>
    <?php
      }
    ?>
  </header>

    <?php
      if (!is_null($cfiletype)) {
        // echo '<div class="wrapper">';
        echo '<div class="container pt-2">';
        echo '<div class="row">';
        echo '<div class="col">';
        if ($cfiletype) {
          require $content;
        } else {
          echo $content;
        }
        // echo '</div></div></div></div>';
        echo '</div></div></div>';
      }
    ?>

  <footer class="footer">
    <div id="footer">
      <div class="row p-5 text-success" style="background-color: #090C9B">
        <div class="col">
          <div class="row">
            <div class="col">
              <h5><?php echo "{$Organisation}"; ?></h5>
              <hr>
              <ul class="list-unstyled">
                <li>site design &amp; development by rc-webs</li>
                <li><?php echo "rc-app v{$version}"; ?></li>
                <li><?php echo date("l, F d Y H:i e"); ?></li>
                <li><span id="datetime">&nbsp;</span></li>
                <li>HOST: <?php echo $HOST; ?></li>
              </ul>
            </div>
            <div class="col">
              <h5>Built with</h5>
              <hr>
              <ul class="list-unstyled">
                <li><a href="https://secure.php.net/">PHP 7.4</a></li>
                <li><a href="https://fatfreeframework.com/3.6/home">Fat Free Framework v3.7</a></li>
                <li><a href="https://www.mysql.com/">MySQL v8.0</a></li>
                <li><a href="https://getbootstrap.com/">Bootstrap v4.5.2</a></li>
              </ul>
            </div>
          </div>
          <ul class="nav">
            <li class="nav-item"><a href="https://facebook.com/edumap.org.za" class="nav-link pl-0"><i class="fab fa-facebook-f"></i></a></li>
            <!--
            <li class="nav-item"><a href="https://www.linkedin.com/" class="nav-link"><i class="fab fa-linkedin"></i></a></li>
            <li class="nav-item"><a href="https://twitter.com/" class="nav-link"><i class="fab fa-twitter"></i></a></li>
            <li class="nav-item"><a href="https://github.com/" class="nav-link"><i class="fab fa-github"></i></a></li>
            <li class="nav-item"><a href="https://instagram.com/" class="nav-link"><i class="fab fa-instagram"></i></a></li>
            -->
          </ul>
          <br>
        </div>
      </div>
    </div>
  </footer>

<!-- Bootstrap core JavaScript
================================================== -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx" crossorigin="anonymous"></script>

<script>
(function() {
  setTimeout(function(){ $(".alert").alert('close'); }, 5000);
  setInterval(function() {
    var d = new Date();
    $("#datetime").text(d.toLocaleTimeString('en-ZA'));
  }, 1000);
})();
</script>

  </body>
</html>