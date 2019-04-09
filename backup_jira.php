<?php
/*

        Request a Jira backup directly from a php application.

        We're running in a cloud environment. For obvious reasons, the provider does not allow shell commands to be executed,
        so powershell or linux scripts are out of the question. This runs straight from php, using cURL.
        
        2019, April 9: tested with php 7.2 and Jira Cloud 8.

        Contributors: Marco Cevat

*/

// define a local function that displays the message in the browser without waiting for the end of the process
        function LogThis($InpMsg)
            {
                echo date('Y-m-d H:i:s') . ' ' . $InpMsg . '<br>';
                flush();
                ob_flush();
            }

// define your specific settings
        $MyDomain           = "YOUR ATLASSIAN DOMAIN";                                              // like xxxx in xxxx.atlassian.net 
        $MyUserId           = "YOUR JIRA EMAIL ADDRESS";                                            // use one with admin rights 
        $MyPassWord         = "THE MATCHING PASSWORD";          
        $JiraBackupFileName = "../ PATH AND FILENAME";                                              // like c:/users/me/dowloads
        $JiraCookieName     = "jiracookie";                                                         // use a unique name for the cookie that will hold the session ID
        $MaxCheckIntervals  = 100;                                                                  // number of progress check intervals
        $ItervalDuration    = 20;                                                                   // duration of an interval between progress checks

// set up a cURL channel
        $ch = curl_init();

// logon to Jira; the session ID is stored in the cookie
        $TargetURL  = "https:/" . $MyDomain . ".atlassian.net/rest/auth/1/session";                 // Jira's REST API logon location
        $PostFields = '{"username": "' . $MyUserId . '", "password": "' . $MyPassWord . '"}';       // Build authorization string
        $headers    = array();                                                                      // Build HTTP header options
        $headers[]  = 'Accept: application/json';
        $headers[]  = 'Content-Type: application/json';

        curl_setopt($ch, CURLOPT_URL, $TargetURL);                                                  // set cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $JiraCookieName);                                       // cookie will hold the logon token
        curl_setopt($ch, CURLOPT_POSTFIELDS, $PostFields);                                          // user and password 
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);                                                                   // execute the request
        if (curl_errno($ch))                                                                        // trap any error
            {
                echo 'Get Jira backup - logon failed: ';
                print_r($result, true);
                exit(FALSE);
            }
        LogThis($LogFileHandle, "Logged on to Jira");

// request a backup
        $TargetURL = "https://" . $MyDomain . ".atlassian.net/rest/backup/1/export/runbackup";      // Jira's REST API export request location
        curl_setopt($ch, CURLOPT_URL, $TargetURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $JiraCookieName);                                       
        // next line: change false->true if you want attachments, but that can only run once every 48 hours 
        curl_setopt($ch, CURLOPT_POSTFIELDS, "{\"cbAttachments\":\"false\", \"exportToCloud\":\"true\"}"); 
        curl_setopt($ch, CURLOPT_POST, 1);

        $result = curl_exec($ch); // execute the request
        if (curl_errno($ch)) // trap any error
            {
                echo 'Get Jira backup - request backup failed: ';
                print_r($result, true);
                exit(FALSE);
            }

        // get the task id to check progress
        $Decoded = json_decode($result, true); // obtain the result
        $TaskId  = $Decoded['taskId']; // get the task ID
        LogThis($LogFileHandle, "Backup started with task id: " . $TaskId);

// check  progress
        $IntervalCounter = 0;
        do
            {
                // count intervals until max-intervals is reached
                $IntervalCounter = $IntervalCounter + 1;

                // Jira's REST API progress reporting URL
                $TargetURL = "https://" . $MyDomain . ".atlassian.net/rest/backup/1/export/getProgress?taskId=" . $TaskId;
                curl_setopt($ch, CURLOPT_URL, $TargetURL);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $JiraCookieName);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

                $result = curl_exec($ch);                                                           // execute the request
                if (curl_errno($ch))                                                                // trap any error
                    {
                        echo 'Get Jira backup - request progress failed: ';
                        print_r($result, true);
                        exit(FALSE);
                    }

                // show progress
                $TaskStatus = print_r($result, true);
                LogThis($LogFileHandle, "Task status: " . $TaskStatus);

                // obtain the result and check for completion: when done, this returns something like {"status":"Success","description":"Cloud Export task","message":"Completed export","result":"export/download/?fileId=xxxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxx","progress":100}
                $Decoded = json_decode($result, true);
                if ($Decoded['status'] == 'Success' AND $Decoded['progress'] == '100')
                    {
                        break;
                    }

                sleep($ItervalDuration);                                                            // wait for the set interval

            } while ($IntervalCounter < $MaxCheckIntervals);

// when the loop end and all intervals have expired, there must be something else wrong
            if ($IntervalCounter >= $MaxCheckIntervals) 
                {
                    echo 'Get Jira backup - waited for more than 30 minutes';
                    exit(FALSE);
                }

// download the created file
        $SourceFilename = $Decoded['result'];                                                       // obtain file ID from progress response
        $TargetURL      = "https://" . $MyDomain . ".atlassian.net/plugins/servlet/" . $SourceFilename; // create the REST API's download URL
        LogThis($LogFileHandle, "File to be loaded: " . $TargetURL);
        $OfileHandle = fopen($JiraBackupFileName, 'w');                                             // open target file name

        curl_setopt($ch, CURLOPT_URL, $TargetURL);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $JiraCookieName);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FILE, $OfileHandle);
        curl_setopt($ch, CURLOPT_ENCODING, "gzip,deflate");                                         // make these explicit; default=all and that does not work

        $result = curl_exec($ch);                                                                   // execute the request
        if (curl_errno($ch))                                                                        // trap any error
            {
                echo 'Get Jira backup - download failed: ';
                print_r($result, true);
                exit(FALSE);
            }

        fclose($OfileHandle);                                                                       // close target file
        curl_close($ch);                                                                            // close cURL connection

        LogThis($LogFileHandle, "File stored as: " . $OfileName);

        exit(TRUE);
