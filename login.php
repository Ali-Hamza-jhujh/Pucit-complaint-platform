<link rel="stylesheet" href="style.css">
<h2>Sign Form</h2>
<div id="div">
<form method="POST">

    

    <label>Email:</label>

    <input
        type="email"
        name="email"
        required
    >

    <br><br>

    <label>Password:</label>

    <input
        type="number"
        name="password"
        required
    >

    


    <button type="submit" name="save" >
        Login Student
    </button>

</form>
</div>
<hr>
<?php

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = $_POST["email"];
     

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

       echo "Invalid email.";

    }
  
   $pass=$_POST["password"];

    

};

$conn = new mysqli(
    "localhost",
    "devuser",
    "2026",
    "devdb"
);

$sql = "SELECT EMAIL,PASSWORD FROM USERS";

$result = $conn->query($sql);
$ll=0;
if ($result->num_rows > 0) {

    while ($row = $result->fetch_assoc()) {
      echo $row["Password"];
        if($row["EMAIL"]===$email && $row["PASSWORD"]===$pass){
            echo "Login successfully!";
            $ll=1;
            break;
        }
      

    }

} else {

    echo "Invalid username and password";

}
if($ll==0)
echo "Invalid username and password";
$conn->close();

?>
