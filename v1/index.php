<?php

require_once '../include/DbHandler.php';
require_once '../include/PassHash.php';
//require '.././libs/Slim/Slim.php';

require '../libs/vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$app = new \Slim\App;

// User id from db - Global Variable
$user_id = NULL;
 
/**
 * Verifying required params posted or not
 */
function verifyRequiredParams($required_fields) {
    $error = false;
    $error_fields = "";
    $request_params = array();
    $request_params = $_REQUEST;

    foreach ($required_fields as $field) {
        if (!isset($request_params[$field]) || strlen(trim($request_params[$field])) <= 0) {
            $error = true;
            $error_fields .= $field . ', ';
        }
    }
 
    if ($error) {
        return false;
    } 
    return true;
}
 
/**
 * Validating email address
 */
function validateEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return true;
}

/**
 * Validate the Authorization header
 */
function authenticate($request) {  
    // Verify Authorization Header
    if ($request->hasHeader('Authorization')) {
        $db = new DbHandler();
        $api_key = $request->getHeaderLine('Authorization');
        
        //validate api key
        if (!$db->isValidApiKey($api_key)) {
            return false;
        } else {
            global $user_id;
            // get user primary key id
            $user = $db->getUserId($api_key);
            if ($user != NULL) {
                $user_id = $user["id"];
                return true;
            }
            return false;                           
        }        
    }
    return false;
}

/*
 *  Validate if headers are present on the request
 */
function checkHeader($request) {
    $headers = $request->getHeaders();
}

/*
 * Validate the answer and return the hint text based on value
 * received on the request. A 10% is configured to evaluate if the answer is 
 * close or not to the real answer.
 */
function checkAnswer($question_answer, $user_answer) {
    if ($question_answer == $user_answer) {
        // We have a winner!!
        return 2;
    } else {
        $close_percentage = 0.10;
        $ten_percent= (int)ceil($question_answer*$close_percentage);
        $answer_difference = abs($user_answer-$question_answer);
        if ($user_answer > $question_answer) {
            // We are on the high range
            if ($answer_difference<$ten_percent)
            {
                // In the range of close high
                return 3;
            } else {
                // In the range of high
                return 4;
            }            
        } else {
            // We are in the low range                        
            if ($answer_difference<$ten_percent)
            {
                // In the range of close low
                return 1;
            } else {
                // In the range of low
                return 0;
            }
        }
    }
    
}

function getHintColumnName($code) {
    $hint_column = "";
        switch ($code) {
            case 0:
                $hint_column = "hint_low";
                break;
            case 1:
                $hint_column = "hint_lowclose";
                break;
            case 2:
                $hint_column = "hint_match";
                break;
            case 3:
                $hint_column = "hint_highclose";
                break;
            case 4:
                $hint_column = "hint_high";
                break;
        }
    return $hint_column;
}

/*
 * First route is for register
 * required parameters: name, email and password
 */
$app->post('/register', function (ServerRequestInterface $request, ResponseInterface $response){
    
    if (!verifyRequiredParams(array('name', 'email', 'password'))) {
        $response_param["error"] = true;
        $response_param["message"] = 'One or more parameters missing or empty';
        return $response->withJson($response_param, 400);
    }
 
    $allPostVars = $request->getParsedBody();
    
    // reading post params
    $name = $allPostVars['name'];
    $email = $allPostVars['email'];
    $password = $allPostVars['password'];


    // validating email address
    if (!validateEmail($email)) {
        $response_param["error"] = true;
        $response_param["message"] = 'Email address is not valid';
        return $response->withJson($response_param, 400);
    }

    // Try to insert user into DB
    $db = new DbHandler();
    $res = $db->createUser($name, $email, $password);
 
    //check response and reply
    if ($res == USER_CREATED_SUCCESSFULLY) {
        $response_param["error"] = false;
        $response_param["message"] = "You are successfully registered";
        return $response->withJson($response_param, 201);
    } else if ($res == USER_CREATE_FAILED) {
        $response_param["error"] = true;
        $response_param["message"] = "Oops! An error occurred while registereing";
        return $response->withJson($response_param, 200);
    } else if ($res == USER_ALREADY_EXISTED) {
        $response_param["error"] = true;
        $response_param["message"] = "Sorry, this email already existed";
        return $response->withJson($response_param, 200);
    }    
});

/*
 * Second route is for login
 * required parameteres; email and password
 */
$app->post('/login', function (ServerRequestInterface $request, ResponseInterface $response) {
    // check for required params
    if (!verifyRequiredParams(array('email', 'password'))) {
        $response_param["error"] = true;
        $response_param["message"] = 'One or more parameters missing or empty';
        return $response->withJson($response_param, 400);
    }

    // reading post params
    $allPostVars = $request->getParsedBody();
    $email = $allPostVars['email'];
    $password = $allPostVars['password'];           

    $db = new DbHandler();
    // check for correct email and password
    if ($db->checkLogin($email, $password)) {                
        //try to get user info
        $user = $db->getUserByEmail($email);                
        if ($user != NULL) {
            $response_param['error'] = false;
            $response_param['name'] = $user['name'];
            $response_param['email'] = $user['email'];
            $response_param['apiKey'] = $user['api_key'];
        } else {
            // unknown error occurred
            $response_param['error'] = true;
            $response_param['message'] = "An error occurred. Please try again";
        }
    } else {
        // user credentials are wrong
        $response_param['error'] = true;
        $response_param['message'] = 'Login failed. Incorrect credentials';
    }
    return $response->withJson($response_param, 200);
});
        
$app->post('/test', function ($request, $response) {
    // check for required params
    if (authenticate($request)) {
        $response_param['error'] = false;
        $response_param['message'] = 'Login successfull. Incorrect credentials';
    } else {
        $response_param['error'] = true;
        $response_param['message'] = 'Login failed. Incorrect credentials';
    }    
    return $response->withJson($response_param, 200);
});

$app->post('/getquestion', function ($request, $response) {
    // check Authorization
    if (!authenticate($request)) {
        $response_param['error'] = true;
        $response_param['message'] = 'Access Denied, please log in again';
        return $response->withJson($response_param, 200);
    }
    
    $db = new DbHandler();
             
    //try to get the most recent question    
    $question = $db->getActiveQuestion(); 
    if ($question != NULL) {
        $response_param['error'] = false;
        $response_param['id'] = $question['id'];
        $response_param['text'] = $question['text'];
        $response_param['answer'] = $question['answer'];
        $response_param['duration_sec'] = $question['duration_sec'];
        $response_param['creation_date'] = $question['creation_date'];
        $response_param['active'] = $question['active'];
    } else {
        // There are no active questions
        $response_param['error'] = true;
        $response_param['message'] = "There are no active questions at this moment. Please try later";
    }
    return $response->withJson($response_param, 200);    
});


$app->post('/getpoints', function ($request, $response) {
    // check Authorization
    if (!authenticate($request)) {
        $response_param['error'] = true;
        $response_param['message'] = 'Access Denied, please log in again';
        return $response->withJson($response_param, 200);
    }
    
    // get api key
    $api_key = $request->getHeaderLine('Authorization');    
    $db = new DbHandler();
    
    // get user_id based on authenticated api_key
    $result = $db->getUserId($api_key);
    $user_id = $result['id'];
             
    //try to get the most recent question    
    $user_points = $db->getUserPoints($user_id);
    if ($user_points != NULL) {
        $response_param['error'] = false;
        $response_param['total_points'] = $user_points;
    } else {
        // There are no active questions
        $response_param['error'] = false;
        $response_param['total_points'] = 0;
    }
    return $response->withJson($response_param, 200);    
});

$app->post('/answer', function ($request, $response) {
    // check Authorization
    if (!authenticate($request)) {
        $response_param['error'] = true;
        $response_param['message'] = 'Access Denied, please log in again';
        return $response->withJson($response_param, 200);
    }
    
    // reading post params
    $allPostVars = $request->getParsedBody();
    $question_id = $allPostVars['id'];
    $user_answer = $allPostVars['answer'];
        
    // get api key
    $api_key = $request->getHeaderLine('Authorization');    
    $db = new DbHandler();
    
    // get user_id based on authenticated api_key
    $result = $db->getUserId($api_key);
    $user_id = $result['id'];
    
    // store user's answer on db
    if ($db->logAnswer($user_id, $question_id, $user_answer)<1) {
        // There are no active questions
        $response_param['error'] = true;
        $response_param['message'] = "There's been a problem, please try again";
    }
    // get the actual question answer
    $question_answer = $db->getQuestionAnswer($question_id);        
    // get the answer rating code
    $answer_code = checkAnswer($question_answer['answer'], $user_answer);
    
    // if the answer is correct add points to user
    if ($answer_code==2) {
        // get api key
        $api_key = $request->getHeaderLine('Authorization'); 
        $result = $db->getUserId($api_key);
        $user_id = $result['id'];
        $db->addPoints($user_id, POINTS_FOR_CORRECT_ANSWER);              
    }
    // now get the text hint to reply to the app
    $hints = $db->getHintTexts($question_id);
    if ($hints != NULL) {
        $hint_column = getHintColumnName($answer_code);
        $hint_text = $hints[$hint_column];
        $response_param['error'] = false;
        $response_param['hint_text'] = $hint_text;
        $response_param['answer_code'] = $answer_code;
    } else {
        // There are no active questions
        $response_param['error'] = true;
        $response_param['message'] = "There's been a problem, please try again";
    }

    #$response_param['error'] = true;
    #$response_param['message'] = $answer_code;
    return $response->withJson($response_param, 200);    
});


$app->post('/getwinners', function ($request, $response) {
    
    // reading post params
    $allPostVars = $request->getParsedBody();
    $question_id = $allPostVars['id'];
    
    $db = new DbHandler();
    
    // get the actual question answer
    $result = $db->getQuestionAnswer($question_id);
    $question_answer = $result['answer'];
                
    //Get the list of winners
    $winner_list = $db->getWinnersList($question_id, $question_answer);
    if ($winner_list != NULL) {
        $response_param['error'] = false;
        $response_param['question_answer'] = $question_answer;
        $response_param['rows'] = $winner_list->num_rows;
        $winners_array = array();
        while($row = $winner_list->fetch_assoc()) {
            $winners_array[] = $row;
        }
        $response_param['winners'] = $winners_array;
    } else {
        // There is nobody playing the game
        $response_param['error'] = true;
        $response_param['message'] = "There are no winners yet";
    }
    return $response->withJson($response_param, 200);    
});

$app->run();

?>