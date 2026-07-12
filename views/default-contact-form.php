<!DOCTYPE html>
<html lang="en">

<head>
  <!-- Required Meta Tags Always Come First -->
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="all">
  <meta name="description" content="email marketing software">
  <meta name="author" content="Richard Catto">
  <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
  <link rel="icon" href="/favicon.ico" type="image/x-icon">

  <title><?php echo $ListName . ' ' . $title; ?></title>
  <!-- Favicon -->
  <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
  <link rel="icon" href="/favicon.ico" type="image/x-icon">

  <!-- Google Fonts -->
  <link rel="stylesheet" type="text/css" href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Noto+Sans|Roboto|Raleway">

  <!-- CSS Global Compulsory -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">

  <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/3.5.2/animate.min.css" rel="stylesheet">

  <!-- CSS Unify -->
  <link rel="stylesheet" type="text/css" href="https://financial-courses.co.za/css/unify-core.css">
  <link rel="stylesheet" type="text/css" href="https://financial-courses.co.za/css/unify-globals.css">
  <link rel="stylesheet" type="text/css" href="https://financial-courses.co.za/css/unify-components.css">

  <!-- CSS Customization -->
  <script src="https://kit.fontawesome.com/5385ffda53.js" crossorigin="anonymous"></script>

</head>

<body>
  <main>
    <section>
      <!-- <div class="g-color-white text-center g-py-150" style="height: 130%; background-image: url(https://financial-courses.co.za/img/44080a.png);"> -->
      <div class="g-color-white text-center g-py-150" style="height: 130%; background-image: url(https://financial-courses.co.za/img/cybernetic-hand-3.png);">
        <!-- <h3 class="h2 g-font-weight-600 g-font-size-48 mb-0">Hello, welcome, pull up a chair</h3> -->
        <!-- <h2 class="g-font-weight-700 g-font-size-65">We are here to help</h2> -->
        <h3 class="h2 g-font-weight-600 g-font-size-48 mb-0">The Future is here</h3>
        <h2 class="g-font-weight-700 g-font-size-65">Let us SHOW you</h2>
      </div>
    </section>

    <!-- Contact Form -->
    <section class="container g-py-100">
      <div class="row justify-content-center g-mb-70">
        <div class="col-lg-7">
          <!-- Heading -->
          <div class="text-center">
            <h2 class="h1 g-color-black g-font-weight-700 text-uppercase mb-4">Tell us about yourself</h2>
            <div class="d-inline-block g-width-70 g-height-2 g-bg-black mb-4"></div>
            <p class="g-font-size-18 mb-0">We would like to help you<br />Please tell us about yourself and what you need</p>
          </div>
          <!-- End Heading -->
        </div>
      </div>

      <div class="row justify-content-center">
        <div class="col-lg-9">
          <form action='<?php echo "{$BaseURL}contact-form"; ?>' method="post" role="form">
            <input name="suid" type="hidden" value="<?php echo $suid; ?>">
            <input name="muid" type="hidden" value="<?php echo $muid; ?>">
            <input name="realm" type="hidden" value="<?php echo $REALM; ?>">
            <div class="form-row">
              <div class="col-md-6 form-group g-mb-20">
                <input class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover rounded-3 g-py-13 g-px-15" type="text" name="cname" placeholder="Full Name" title="Full Name" required>
              </div>

              <div class="col-md-6 form-group g-mb-20">
                <input class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover rounded-3 g-py-13 g-px-15" type="email" name="cemail" placeholder="Email Address" title="Email Address" required>
              </div>

              <div class="col-md-6 form-group g-mb-20">
                <input class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover rounded-3 g-py-13 g-px-15" type="text" name="ccell" placeholder="Cell Number" title="Cell Number" required>
              </div>

              <div class="col-md-6 form-group g-mb-20">
                <input class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover rounded-3 g-py-13 g-px-15" type="text" name="cweb" placeholder="Website" title="Website" required>
              </div>

              <div class="col-md-6 form-group g-mb-20">
                <input class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover rounded-3 g-py-13 g-px-15" type="text" name="ccompany" placeholder="Name of Company" title="Name of Company" required>
              </div>

              <div class="col-md-6 form-group g-mb-20">
                <input class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover rounded-3 g-py-13 g-px-15" type="text" name="ctopic" placeholder="Topic / Subject" title="Topic / Subject" required>
              </div>

              <div class="col-md-12 form-group g-mb-40">
                <textarea class="form-control g-color-black g-bg-white g-bg-white--focus g-brd-gray-light-v3 g-brd-primary--hover g-resize-none rounded-3 g-py-13 g-px-15" rows="7" name="cmessage" placeholder="How can we help you?" title="How can we help you?" required></textarea>
              </div>
            </div>

            <div class="text-center">
              <button class="btn u-btn-primary g-font-weight-600 g-font-size-13 text-uppercase g-rounded-25 g-py-15 g-px-30" type="submit" role="button">Send Request</button>
            </div>
          </form>
        </div>
      </div>
    </section>
    <!-- End Contact Form -->

    <!-- Footer -->
    <!-- <div id="contacts-section" class="g-bg-black-opacity-0_9 g-color-white-opacity-0_8 g-py-60" style="background-image: url(https://financial-courses.co.za/img/90045a.png);"> -->
    <div id="contacts-section" class="g-bg-black-opacity-0_9 g-color-white-opacity-0_8 g-py-60" style="background-image: url(https://financial-courses.co.za/img/footer-b1.png);">
      <div class="container">
        <div class="row">
          <!-- Footer Content -->
          <div class="col-lg-4 col-md-4 g-mb-40 g-mb-0--lg">
            <div class="u-heading-v2-3--bottom g-brd-white-opacity-0_8 g-mb-20">
              <h2 class="u-heading-v2__title h6 text-uppercase mb-0">About us</h2>
            </div>

            <p><?php echo "{$Organisation}"; ?></p>
            <p><i class="fas fa-map-marker-alt"></i><?php echo " {$StreetAddress}"; ?></p>
            <p><i class="fas fa-mobile-alt"></i><?php echo " {$Telephone}"; ?></p>
            <p><i class="fab fa-whatsapp"></i><?php echo "<a href=\"{$WhatsAppURL}\" target=_blank> {$WhatsApp}</a>"; ?></p>
          </div>
          <!-- End Footer Content -->

          <!-- Footer Content -->
          <div class="col-lg-4 col-md-4 g-mb-40 g-mb-0--lg">
            <div class="u-heading-v2-3--bottom g-brd-white-opacity-0_8 g-mb-20">
              <h2 class="u-heading-v2__title h6 text-uppercase mb-0">Site design</h2>
            </div>

            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover">site design &amp; development by rc-webs</h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><?php echo "generated by rc-app v{$version}"; ?></h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><?php echo date("l, F d Y H:i e"); ?></h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><span id="datetime">&nbsp;</span></h3>
          </div>
          <!-- End Footer Content -->

          <!-- Footer Content -->
          <div class="col-lg-4 col-md-4 g-mb-40 g-mb-0--lg">
            <div class="u-heading-v2-3--bottom g-brd-white-opacity-0_8 g-mb-20">
              <h2 class="u-heading-v2__title h6 text-uppercase mb-0">Software Technologies</h2>
            </div>

            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><a href="https://secure.php.net/">PHP 7.4</a></h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><a href="https://fatfreeframework.com/3.6/home">Fat Free Framework v3.7</a></h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><a href="https://www.mysql.com/">MySQL v8.0</a></h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><a href="https://getbootstrap.com/">Bootstrap v4.5.2</a></h3>
            <hr class="g-brd-white-opacity-0_1 g-my-10">
            <h3 class="h6 g-mb-2 g-color-white-opacity-0_8 g-color-white--hover"><a href="https://vuejs.org/">VueJS</a></h3>
          </div>
          <!-- End Footer Content -->

        </div>
      </div>
    <!-- End Footer -->
    </div>

    <!-- Copyright Footer -->
    <footer class="g-bg-gray-dark-v1 g-color-white-opacity-0_8 g-py-20" style="bottom: 0;">
      <div class="container">
        <div class="row">
          <div class="col-md-8 text-center text-md-left g-mb-10 g-mb-0--md">
            <div class="d-lg-flex">
              <small class="d-block g-font-size-default g-mr-10 g-mb-10 g-mb-0--md">2018 © All Rights Reserved.</small>
              <ul class="u-list-inline">
                <li class="list-inline-item">
                  <a class="g-color-white-opacity-0_8 g-color-white--hover" href="/">Home</a>
                </li>
                <li class="list-inline-item">
                  <span>|</span>
                </li>
                <li class="list-inline-item">
                  <a class="g-color-white-opacity-0_8 g-color-white--hover" href="/privacy">Privacy Policy</a>
                </li>
                <li class="list-inline-item">
                  <span>|</span>
                </li>
                <li class="list-inline-item">
                  <a class="g-color-white-opacity-0_8 g-color-white--hover" href="">Contact us</a>
                </li>
                <li class="list-inline-item">
                  <span>|</span>
                </li>
                <li class="list-inline-item">
                  <a class="g-color-white-opacity-0_8 g-color-white--hover" href="/subscribe">Subscribe</a>
                </li>
              </ul>
            </div>
          </div>

          <div class="col-md-4 align-self-center">
            <ul class="list-inline text-center text-md-right mb-0">
              <li class="list-inline-item g-mx-10" data-toggle="tooltip" data-placement="top" title="Facebook">
                <a href="https://facebook.com/" class="g-color-white-opacity-0_5 g-color-white--hover">
                  <i class="fa fa-facebook"></i>
                </a>
              </li>
              <li class="list-inline-item g-mx-10" data-toggle="tooltip" data-placement="top" title="Skype">
                <a href="https://skype.com/" class="g-color-white-opacity-0_5 g-color-white--hover">
                  <i class="fa fa-skype"></i>
                </a>
              </li>
              <li class="list-inline-item g-mx-10" data-toggle="tooltip" data-placement="top" title="Linkedin">
                <a href="https://www.linkedin.com/" class="g-color-white-opacity-0_5 g-color-white--hover">
                  <i class="fa fa-linkedin"></i>
                </a>
              </li>
              <li class="list-inline-item g-mx-10" data-toggle="tooltip" data-placement="top" title="Pinterest">
                <a href="https://pinterest.com/" class="g-color-white-opacity-0_5 g-color-white--hover">
                  <i class="fa fa-pinterest"></i>
                </a>
              </li>
              <li class="list-inline-item g-mx-10" data-toggle="tooltip" data-placement="top" title="Twitter">
                <a href="https://twitter.com/" class="g-color-white-opacity-0_5 g-color-white--hover">
                  <i class="fa fa-twitter"></i>
                </a>
              </li>
            </ul>
          </div>

        </div>
      </div>
    </footer>
  </main>

<!-- Bootstrap core JavaScript
================================================== -->
<!-- Placed at the end of the document so the pages load faster -->
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
