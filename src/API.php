<?php


namespace MGGFLOW\VK;


class API
{
    /**
     * Массив ошибок от ВК
     * @var array
     */
    public $errors = [];
    /**
     * Статистика выполнения по запросам
     * @var array
     */
    public $statistic = [];
    /**
     * Лог выполнения запросов
     * @var array
     */
    public $log = [];

    /**
     * Флаг учёта параметров запроса в ошибке при регистрации
     * @var bool
     */
    public $extendedErrors = true;
    /**
     * Флаг использования Curl для отправки запроса
     * @var bool
     */
    public $useCurl = false;
    /**
     * Используемая версия API
     * @var float
     */
    public $version = 5.106;
    /**
     * Массив парамтеров запроса
     * @var array
     */
    protected $methodParams;
    /**
     * Назвение метода
     * @var string
     */
    protected $methodName;
    /**
     * Токен доступа к API
     * @var string
     */
    protected $token;

    /**
     * Название области метода
     * @var string
     */
    protected $methodArea;

    /**
     * Конструктор с возможностью задать токен доступа и используемую версию
     * @param false|string $token
     * @param false|float $apiVersion
     */
    public function __construct($token = false,$apiVersion=false)
    {
        if (!empty($token)) {
            $this->setToken($token);
        }

        if (!empty($apiVersion)) {
            $this->setVersion($apiVersion);
        }

        return $this;
    }

    /**
     * Сеттер для токена
     * @param $token
     */
    public function setToken($token)
    {
        $this->token = $token;
    }

    /**
     * Геттер, устанавливающий область метода
     * @param $area
     * @return $this
     */
    public function __get($area)
    {
        $this->methodArea = $area;

        return $this;
    }

    /**
     * Сеттер версии для работы с API
     * @param $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Анализирует ответ API ВК, позволяет обработать ошибки с лимитами и добиться разультата в несколько попыток
     * @param $response
     * @param false $try
     * @param int $attempts
     * @param false $wait
     * @param int $attempt
     * @return false|object
     */
    public function exploreResponse(&$response, $try = false, $attempts = 3, $wait = false, $attempt = 0)
    {
        if (isset($response->response)) {
            return $response->response;
        } elseif (isset($response->error)) {
            $errorCode = $response->error->error_code;
            if ($try) {
                if ($wait) {
                    $waitTime = $this->calcWaitTime($errorCode);

                    usleep($waitTime);
                }
                if ($attempt < $attempts) {
                    $response = $this->execute();

                    return $this->exploreResponse($response, $try, $attempts, $wait, $attempt + 1);
                }
            }
        }

        return false;
    }

    /**
     * Вычисление времени ожидания для кода ошибки апи ВК
     * @param $errorCode
     * @return float|int
     */
    protected function calcWaitTime($errorCode)
    {
        $waitTime = 0;
        // много запросов в секунду
        if ($errorCode == 6) {
            $waitTime = 1.5;
        }
        // много однотипных действий
        if ($errorCode == 9) {
            $waitTime = 1.5;
        }
        // капча
        if ($errorCode == 14) {
            $waitTime = 1.0;
        }

        return $waitTime * 1000000;
    }

    /**
     * Выполняет текущий запрос к API
     * @return mixed
     */
    protected function execute()
    {
        $methodFullName = $this->genMethodFullName($this->methodName);

        $startRequest = microtime(true);
        $response = $this->uniMethod($methodFullName, $this->methodParams);
        $endRequest = microtime(true);
        $elapsedTime = $endRequest - $startRequest;

        $this->handleResponse($methodFullName, $response, $elapsedTime);

        return $response;
    }

    /**
     * Генератор полного имени метода
     * @param $methodName
     * @return string
     */
    protected function genMethodFullName($methodName)
    {
        return $this->methodArea . '.' . $methodName;
    }

    /**
     * Метод отправки запроса
     * @param $methodFullName
     * @param $post
     * @return mixed
     */
    protected function uniMethod($methodFullName, $post)
    {
        $request_url = 'https://api.vk.com/method/' . $methodFullName;
        $this->providePost($post);

        if ($this->useCurl) {
            $data = $this->curlPost($request_url, $post);
        } else {
            $query = http_build_query($post);
            $data = file_get_contents($request_url . '?' . $query);
        }

        return json_decode($data);
    }

    protected function curlPost($url,$post,$timeout=60,$jsonDecode=false,$jsonDecodeAssoc=false){
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        $out = curl_exec($curl);
        curl_close($curl);
        if ($out) {
            return ($jsonDecode)
                ? json_decode($out,$jsonDecodeAssoc)
                : $out;
        }

        return false;
    }

    /**
     * Добавление токена доступа и версии API в данные запроса
     * @param $post
     */
    protected function providePost(&$post)
    {
        if (!is_array($post)) return;
        if (!isset($post['v'])) $post['v'] = $this->version;
        if (!isset($post['access_token'])) $post['access_token'] = $this->token;
    }

    /**
     * Обрабатывает ответ апи ВК
     * @param $methodFullName
     * @param $response
     * @param $elapsedTime
     */
    protected function handleResponse($methodFullName, $response, $elapsedTime)
    {
        $errorIndex = $this->provideError($methodFullName, $response);

        $logIndex = $this->provideLog($methodFullName, $errorIndex, $elapsedTime);

        $logNote = $this->log[$logIndex];
        $this->addStatistic($logNote['method'], $logNote['result'], $logNote['elapsedTime']);
    }

    /**
     * Обеспечение учёта ошибки из ответа API
     * @param $methodFullName
     * @param $response
     * @return false|int
     */
    protected function provideError($methodFullName, $response)
    {
        if (!isset($response->error) and !empty($response->response)) return false;

        $error = $this->createError($methodFullName, $response);

        return $this->addError($error);
    }

    /**
     * Создание массива ошибки
     * @param $methodFullName
     * @param $response
     * @return array
     */
    protected function createError($methodFullName, $response)
    {
        $error = [
            'method' => $methodFullName,
            'params' => false,
            'timestamp' => microtime(true),
        ];
        if (isset($response->error)) {
            $error['code'] = $response->error->error_code;
            $error['message'] = $response->error->error_msg;
            if ($this->extendedErrors) {
                $error['params'] = $response->error->request_params;
            }
        } else {
            $error['code'] = -1;
            $error['message'] = 'Unexpected error';
        }

        return $error;
    }

    /**
     * Метод добавления ошибки
     * @param $error
     * @return int
     */
    protected function addError($error)
    {
        $counter = array_push($this->errors, $error);
        return ($counter - 1);
    }

    /**
     * Регистрирует выполнения запроса в логе
     * @param $methodFullName
     * @param $errorIndex
     * @param $elapsedTime
     * @return int
     */
    protected function provideLog($methodFullName, $errorIndex, $elapsedTime)
    {
        $logNote = [
            'method' => $methodFullName,
            'elapsedTime' => $elapsedTime,
            'timestamp' => microtime(true),
        ];
        if ($errorIndex === false) {
            $logNote['result'] = true;
            $logNote['error'] = false;
        } else {
            $logNote['result'] = false;
            $logNote['error'] = &$this->errors[$errorIndex];
        }

        return $this->addLogNote($logNote);
    }

    /**
     * Добавляет запись в лог и возвращает её index
     * @param $note
     * @return int
     */
    protected function addLogNote($note)
    {
        $counter = array_push($this->log, $note);
        return ($counter - 1);
    }

    /**
     * Учёт результата выполнения запроса для статистики
     * @param $methodFullName
     * @param $result
     * @param $elapsedTime
     */
    protected function addStatistic($methodFullName, $result, $elapsedTime)
    {
        if (!isset($this->statistic[$methodFullName])) $this->statistic[$methodFullName] = [
            'successCounter' => 0,
            'successTime' => 0,
            'failCounter' => 0,
            'failTime' => 0,
        ];
        if ($result) {
            $this->statistic[$methodFullName]['successCounter']++;
            $this->statistic[$methodFullName]['successTime'] += $elapsedTime;
        } else {
            $this->statistic[$methodFullName]['failCounter']++;
            $this->statistic[$methodFullName]['failTime'] += $elapsedTime;
        }
    }

    /**
     * Магический метод вызова метода API, выполняющий его
     * @param $methodName
     * @param $arguments
     * @return mixed
     */
    public function __call($methodName, $arguments)
    {
        $this->methodName = $methodName;
        $this->methodParams = $arguments[0];

        return $this->execute();
    }
}