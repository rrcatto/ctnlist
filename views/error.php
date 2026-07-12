<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $ERROR['code'] . " " . $ERROR['status']; ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">
    <link href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet">
    <link href="//cdnjs.cloudflare.com/ajax/libs/animate.css/3.2.6/animate.min.css" rel="stylesheet">
</head>

<body class="gray-bg">

    <div class="middle-box text-center animated fadeInDown">
        <h1 class="text-navy"><?php echo $ERROR['code']; ?></h1>
        <h3 class="font-bold"><?php echo $ERROR['status']; ?></h3>

        <div class="error-desc">
            <?php
              echo "<p>" . $ERROR['text'] . "</p>";
              if ($ERROR['code'] == 404) {
                echo "<p>The page you tried to access is not available</p>";
              }
            ?>
            <p>You can go back to the home page: <br/><a href="/" class="btn btn-primary m-t">Home</a></p>
        </div>
    </div>

<!-- Bootstrap core JavaScript
================================================== -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx" crossorigin="anonymous"></script>
</body>

</html>
