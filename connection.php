<?php
    $host = "localhost";
    $user = "hr3_viahale";
    $password ="pogi";
    $database = "hr3_viahale";


    $conn = mysqli_connect($host, $user, $password, $database);

    if(!$conn){
        die("Connection Failed: " . mysqli_connect_error());
    }
?>