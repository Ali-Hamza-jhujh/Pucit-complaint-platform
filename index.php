<link rel="stylesheet" href="style.css">
<h1 >Sign Form</h1>
<div id="div">

<form method="POST">

    <label>Name: <br> </label>

    <input
        type="text"
        name="name"
        required
    >

    <br><br>

    <label>Email: <br></label>

    <input
        type="email"
        name="email"
        required
    >

    <br><br>

    <label>Password: <br></label>

    <input
        type="number"
        name="password"
        required
    >

    <br><br>
    <label>confirm password: <br></label>

    <input
        type="number"
        name="c_password"
        required
    >

    <br><br>


   <button type="submit" name="save" id="btn">
        Save Student
    </button>

</form>
 
</div>

<?php

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = $_POST["email"];
     

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

       echo "Invalid email.";

    }
  
   $name=$_POST["name"];
   $pass=$_POST["password"];
   $c_pass=$_POST["c_password"];
   if($pass!=$c_pass){
    echo "Your password and confirm password mismatch";
    }   
    

};

$conn = new mysqli(
            "localhost",
            "devuser",
            "2026",
            "devdb"
        );

        if ($conn->connect_error) {

            die(
                "Database connection failed: "
                . $conn->connect_error
            );

        }
       
          
 $sql = "
INSERT INTO USERS(NAME, EMAIL, PASSWORD)
VALUES ('$name', '$email', $pass)
";

if ($conn->query($sql) === TRUE) {

    echo "Student inserted.";

} else {

    echo "Error: " . $conn->error;

}

$conn->close();

?>
