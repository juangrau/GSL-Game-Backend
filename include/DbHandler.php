<?php

/**
 * Class to handle all db operations
 * This class will have CRUD methods for database tables
 *
 * @author Ravi Tamada
 */
class DbHandler {
 
    private $conn;
 
    function __construct() {
        require_once dirname(__FILE__) . './DbConnect.php';
        // opening db connection
        $db = new DbConnect();
        $this->conn = $db->connect();
    }
 
    /* ------------- `users` table method ------------------ */
 
    /**
     * Creating new user
     * @param String $name User full name
     * @param String $email User login email id
     * @param String $password User login password
     */
    public function createUser($name, $email, $password) {
        require_once 'PassHash.php';
        $response = array();
 
        // First check if user already existed in db
        if (!$this->isUserExists($email)) {
            // Generating password hash
            $password_hash = PassHash::hash($password);
 
            // Generating API key
            $api_key = $this->generateApiKey();
 
            // insert query
            $stmt = $this->conn->prepare("INSERT INTO users(name, email, password_hash, api_key, status) values(?, ?, ?, ?, 1)");
            $stmt->bind_param("ssss", $name, $email, $password_hash, $api_key);
 
            $result = $stmt->execute();
 
            $stmt->close();
 
            // Check for successful insertion
            if ($result) {
                // User successfully inserted
                return USER_CREATED_SUCCESSFULLY;
            } else {
                // Failed to create user
                return USER_CREATE_FAILED;
            }
        } else {
            // User with same email already existed in the db
            return USER_ALREADY_EXISTED;
        }
 
        return $response;
    }
 
    /**
     * Checking user login
     * @param String $email User login email id
     * @param String $password User login password
     * @return boolean User login status success/fail
     */
    public function checkLogin($email, $password) {
        // fetching user by email
        $stmt = $this->conn->prepare("SELECT password_hash FROM users WHERE email = ?");
 
        $stmt->bind_param("s", $email);
 
        $stmt->execute();
 
        $stmt->bind_result($password_hash);
 
        $stmt->store_result();
 
        if ($stmt->num_rows > 0) {
            // Found user with the email
            // Now verify the password
 
            $stmt->fetch();
 
            $stmt->close();
 
            if (PassHash::check_password($password_hash, $password)) {
                // User password is correct
                return TRUE;
            } else {
                // user password is incorrect
                return FALSE;
            }
        } else {
            $stmt->close();
 
            // user not existed with the email
            return FALSE;
        }
    }
 
    /**
     * Checking for duplicate user by email address
     * @param String $email email to check in db
     * @return boolean
     */
    private function isUserExists($email) {
        $stmt = $this->conn->prepare("SELECT id from users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }
 
    /**
     * Fetching user by email
     * @param String $email User email id
     */
    public function getUserByEmail($email) {
        $stmt = $this->conn->prepare("SELECT name, email, api_key, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        if ($stmt->execute()) {
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $user;
        } else {
            return NULL;
        }
    }
 
    /**
     * Fetching user api key
     * @param String $user_id user id primary key in user table
     */
    public function getApiKeyById($user_id) {
        $stmt = $this->conn->prepare("SELECT api_key FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $api_key = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $api_key;
        } else {
            return NULL;
        }
    }
 
    /**
     * Fetching user id by api key
     * @param String $api_key user api key
     */
    public function getUserId($api_key) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE api_key = ?");
        $stmt->bind_param("s", $api_key);
        if ($stmt->execute()) {
            $user_id = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $user_id;
        } else {
            return NULL;
        }
    }
 
    /**
     * Validating user api key
     * If the api key is there in db, it is a valid key
     * @param String $api_key user api key
     * @return boolean
     */
    public function isValidApiKey($api_key) {
        $stmt = $this->conn->prepare("SELECT id from users WHERE api_key = ?");
        $stmt->bind_param("s", $api_key);
        $stmt->execute();
        $stmt->store_result();
        $num_rows = $stmt->num_rows;
        $stmt->close();
        return $num_rows > 0;
    }
 
    /**
     * Generating random Unique MD5 String for user Api key
     */
    private function generateApiKey() {
        return md5(uniqid(rand(), true));
    }
    
    /* ------------- questions related methods ------------------ */
    
    /**
     * Fetch most recent active question
     */
    public function getActiveQuestion() {
        $stmt = $this->conn->prepare("select * from questions where active=1 order by creation_date DESC LIMIT 1");
        $stmt->execute();
        $question = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $question;
    }
    
    /**
     * Fetch total points accumulated by a user
     * @param int $user_id 
     */
    public function getUserPoints($user_id) {
        $stmt = $this->conn->prepare("select total_points from points where user_id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_points = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user_points['total_points']!=NULL) {
            return $user_points['total_points'];
        } else {
            return 0;
        }
    }
    
    /**
     * Add points to a user
     * @param int $user_id 
     * @param int $points
     */
    public function addPoints($user_id, $points) {
        //get the current points
        $current_points = $this->getUserPoints($user_id);
        $new_score = $current_points + $points;
        if ($current_points>0) {
            $stmt = $this->conn->prepare("update points set total_points = ? where user_id=?");
            $stmt->bind_param("ii", $new_score, $user_id);
        } else {
            $stmt = $this->conn->prepare("insert into points (user_id, total_points) values "
                    . "(?, ?)");
            $stmt->bind_param("ii", $user_id, $new_score);
        }        
        $stmt->execute();
        $num_affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $num_affected_rows;
    }
    
    
    /*
     * Get questions answer by question id
     */    
    public function getQuestionAnswer($question_id) {
        $stmt = $this->conn->prepare("select answer from questions where id=?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $answer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $answer;
    }
    
    /*
     * Get answer hint texts based on question id
     */
    public function getHintTexts($question_id) {        
        $stmt = $this->conn->prepare("select * from hints where qid=?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $hints = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $hints;
    }
    
    /**
     * Register user's answer on DB
     * @param int $user_id 
     * @param int $question_id
     * @param int answer
     */
    public function logAnswer($user_id, $question_id, $answer) {
        //get the current points
        $stmt = $this->conn->prepare("insert into answers (user_id, question_id, answer, answer_date) values "
                    . "(?, ?, ?, now())");
            $stmt->bind_param("iii", $user_id, $question_id, $answer);       
        $stmt->execute();
        $num_affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $num_affected_rows;
    }
    
    
    /*
     * Get the list of winners
     */
    public function getWinnersList($question_id, $question_answer) {        
        $stmt = $this->conn->prepare("select user_id, answer, abs(answer-?), answer_date from answers "
                . "where question_id=? order by abs(answer-?), answer_date desc");
        $stmt->bind_param("iii", $question_answer, $question_id, $question_answer);
        $stmt->execute();
        $winners = $stmt->get_result();
        $stmt->close();
        return $winners;
    }
 
}
 
?>
