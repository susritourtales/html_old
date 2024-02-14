<?php

namespace Application\Controller;

use Admin\Model\CitiesTable;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Authentication\AuthenticationService;
use Laminas\Db\Adapter\Adapter;
use Laminas\Session\Container as SessionContainer;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use Aws\CommandPool;
use Aws\Sdk;

use Admin\Model\LanguageTable;
use Admin\Model\CountriesTable;
use Admin\Model\PlacesTable;
use Admin\Model\StatesTable;
use Admin\Model\TemporaryFilesTable;
use Admin\Model\TourismFilesTable;
use Admin\Model\TourTalesTable;
use Admin\Model\UserTable;

class BaseController extends AbstractActionController
{
    protected $authService;
    protected $dbAdapter;
    protected $sessionManager;
    protected $sessionContainer;
    protected $s3;
    protected $config;

    protected $userTable;
    protected $languageTable;
    protected $temporaryFiles;
    protected $tourismFilesTable;
    protected $countriesTable;
    protected $statesTable;
    protected $placesTable;
    protected $citiesTable;
    protected $tourTalesTable;


    public function __construct(
        AuthenticationService $authService,
        Adapter $dbAdapter,
        LanguageTable $language_table,
        CountriesTable $countries_table,
        StatesTable $states_table,
        CitiesTable $cities_table,
        PlacesTable $places_table,
        TourTalesTable $tour_tales_table,
        TemporaryFilesTable $temporary_files_table,
        TourismFilesTable $tourism_files_table,
        UserTable $user_table
    ) {
        $this->sessionContainer = new SessionContainer('stt_session');
        $this->sessionManager = $this->sessionContainer->getManager();
        $this->sessionManager->rememberMe(604800); // = 60 * 60 * 24 * 7

        $this->authService = $authService;
        $this->dbAdapter = $dbAdapter;

        $this->languageTable = $language_table;
        $this->countriesTable = $countries_table;
        $this->statesTable = $states_table;
        $this->citiesTable = $cities_table;
        $this->placesTable = $places_table;
        $this->tourTalesTable = $tour_tales_table;
        $this->temporaryFiles = $temporary_files_table;
        $this->tourismFilesTable = $tourism_files_table;
        $this->userTable = $user_table;
    }

    public function getLoggedInUserId()
    {
        try {
            $user = $this->getLoggedInUser();
            if ($user) {
                return $user['user_login_id'];
            } else {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getLoggedInUser()
    {
        try {
            $user = $this->getAuthService()->getIdentity();
            //$user = $this->getLoggedInUser();
            return $user;
        } catch (\Exception $e) {
            return array();
        }
    }

    public function getAuthService()
    {
        try {
            if (!$this->authService) {
                $sm = $this->getEvent()->getApplication()->getServiceManager();
                $this->authService = $sm->get('AuthService');
            }
            return $this->authService;
        } catch (\Exception $e) {
            return array();
        }
    }

    public function getBaseUrl()
    {
        $event = $this->getEvent();
        $request = $event->getRequest();
        $router = $event->getRouter();
        $uri = $router->getRequestUri();
        return $baseUrl = sprintf('%s://%s%s', $uri->getScheme(), $uri->getHost(), $request->getBaseUrl());
    }

    public function checkAdmin()
    {
        if (!$this->authService->hasIdentity()) {
            return $this->redirect()->toUrl($this->getBaseUrl() . '/a_dMin/login');
        }
    }

    public function filesUrl()
    {
        return 'https://s3.amazonaws.com/files.susri/';
    }

    public function copypushFiles($files)
    {
        $serviceLocator = $this->getEvent()->getApplication()->getServiceManager();
        if ($this->s3 == null || $this->config == null) {
            $this->config = $serviceLocator->get('Config');
            $this->config = isset($this->config['aws']) ? $this->config['aws'] : array();
            $sdk = new Sdk($this->config);
            $this->s3 = $sdk->createS3();
        }

        // header("Content-Type: image/jpg");
        $i = 0;
        $copiedFiles = array();
        $fileIds = array();
        $i = -1;
        $value = array();
        foreach ($files as $file) {
            $i++;
            $fileIds[$i] = $file['id'];
            $value[] = $this->s3->getCommand('CopyObject', [
                'Bucket'     =>  $this->config["bucket"],
                'Key'        => "{$file['new_path']}",
                'CopySource' => "{$this->config["bucket"]}/tmp/{$file['old_path']}",
            ]);
        }

        try {
            if (count($value)) {
                $results = CommandPool::batch($this->s3, $value);
                foreach ($results as $result) {

                    if ($result instanceof ResultInterface) {
                        // Result handling here
                        array_push($copiedFiles, $fileIds[$i]);
                    }
                    if ($result instanceof AwsException) {
                        // AwsException handling here
                    }
                }
                if (count($copiedFiles) == count($fileIds)) {
                    return array('status' => true, 'copied' => $copiedFiles);
                } else {
                    return array('status' => false, 'copied' => $copiedFiles);
                }
            } else {
                return array('status' => true, 'copied' => $copiedFiles);
            }
        } catch (\Exception $e) {
            /* print_r($e->getMessage());
            exit; */
            return false;
        }
        return true;
    }

    public function pushFiles($fileName, $filepath, $attachmentType)
    {
        try {
            $serviceLocator = $this->getEvent()->getApplication()->getServiceManager();
            if ($this->s3 == null || $this->config == null) {
                $this->config = $serviceLocator->get('Config');
                $this->config = isset($this->config['aws']) ? $this->config['aws'] : array();
                $sdk = new Sdk($this->config);
                $this->s3 = $sdk->createS3();
            }
            // header("Content-Type: image/jpg");
            $value = $this->s3->putObject(array(
                'Bucket' => $this->config["bucket"],
                'Key' =>  $fileName,
                'Body' => fopen($filepath, 'r'),
                'ACL' => 'public-read',
                "Cache-Control" => "max-age=94608000",
                "ContentType" => $attachmentType,
                "signatureVersion" => "v4",
                "Expires" => gmdate(
                    "D, d M Y H:i:s T",
                    strtotime("+36 years")
                ),
                "Metadata" => array(
                    'Expires' => gmdate(
                        "D, d M Y H:i:s T",
                        strtotime("+36 years")
                    )
                )
            ));
            return $value;
        } catch (\Exception $e) {
            return false;
        }
    }

    function getDuration($file)
    {
        $dur = shell_exec("ffmpeg -i " . $file . " 2>&1");
        if (preg_match("/: Invalid /", $dur)) {
            return false;
        }
        preg_match("/Duration: (.{2}):(.{2}):(.{2})/", $dur, $duration);
        if (!isset($duration[1])) {
            return false;
        }
        return $duration[1] . ":" . $duration[2] . ":" . $duration[3];
    }

    function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString . "_" . strtotime(date("Y-m-d H:i:s"));
    }

    function random_password($length = 8)
    {
        $chars = "0123456789";
        $password = substr(str_shuffle($chars), 0, $length);
        return $password;
    }

    function randomNumericpassword($length = 8)
    {

        $chars = "0123456789";
        $password = substr(str_shuffle($chars), 0, $length);
        return $password;
    }
}
