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
    public $version = 5.131;

    /**
     * Токен доступа к API
     * @var string
     */
    protected $token;
    /**
     * Массив парамтеров запроса
     * @var array
     */
    protected $methodParams = [];
    /**
     * Назвение метода
     * @var string
     */
    protected $methodName;
    /**
     * Название области метода
     * @var string
     */
    protected $methodArea;
    /**
     * Полное название метода АПИ
     * @var string
     */
    protected $methodFullName = '';

    /**
     * Последний полученный ответ
     * @var object|null
     */
    public $response;

    /**
     * Конструктор с возможностью задать токен доступа и используемую версию
     * @param false|string $token
     * @param false|float $apiVersion
     */
    public function __construct($token = false, $apiVersion = false)
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
     * Анализирует ответ API ВК, позволяет обработать ошибки с лимитами и добиться результата в несколько попыток
     * @param false $try
     * @param int $attempts
     * @param false $wait
     * @param int $attempt
     * @return false|object|array
     */
    public function explore(bool $try = false, int $attempts = 3, bool $wait = false, int $attempt = 0)
    {
        if (isset($this->response->response)) {
            return $this->response->response;
        } elseif (isset($this->response->error)) {
            $errorCode = $this->response->error->error_code;
            if ($try) {
                if ($wait) {
                    $waitTime = $this->calcWaitTime($errorCode);

                    usleep($waitTime);
                }
                if ($attempt < $attempts) {
                    return $this->execute()->explore($try, $attempts, $wait, $attempt + 1);
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
     * @return self
     */
    protected function execute(): self
    {
        $this->genMethodFullName();

        $startRequest = microtime(true);
        $this->response = $this->uniMethod();
        $endRequest = microtime(true);
        $elapsedTime = $endRequest - $startRequest;

        $this->collectResponseMetadata($elapsedTime);

        return $this;
    }

    /**
     * Генератор полного имени метода
     */
    protected function genMethodFullName()
    {
        $this->methodName = $this->methodArea . '.' . $this->methodName;
    }

    /**
     * Метод отправки запроса
     * @return mixed
     */
    protected function uniMethod()
    {
        $request_url = 'https://api.vk.com/method/' . $this->methodFullName;
        $this->providePost($this->methodParams);

        if ($this->useCurl) {
            $data = $this->curlPost($request_url, $this->methodParams);
        } else {
            $query = http_build_query($this->methodParams);
            $data = file_get_contents($request_url . '?' . $query);
        }

        return json_decode($data);
    }

    /**
     * Отправить запрос с помощью Curl.
     * @param $url
     * @param $post
     * @param int $timeout
     * @param false $jsonDecode
     * @param false $jsonDecodeAssoc
     * @return bool|mixed|string
     */
    protected function curlPost($url, $post, int $timeout = 60, bool $jsonDecode = false, bool $jsonDecodeAssoc = false)
    {
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
                ? json_decode($out, $jsonDecodeAssoc)
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
     * @param $elapsedTime
     */
    protected function collectResponseMetadata($elapsedTime)
    {
        $errorIndex = $this->collectError();

        $logIndex = $this->logResponse($errorIndex, $elapsedTime);
        $logNote = $this->log[$logIndex];

        $this->addStatistic($logNote['result'], $logNote['elapsedTime']);
    }

    /**
     * Обеспечение учёта ошибки из ответа API
     * @return false|int
     */
    protected function collectError()
    {
        if (!isset($this->response->error) and !empty($this->response->response)) return false;

        $error = $this->createResponseError();

        return $this->addError($error);
    }

    /**
     * Создание массива ошибки
     * @return array
     */
    protected function createResponseError(): array
    {
        $error = [
            'method' => $this->methodFullName,
            'params' => false,
            'timestamp' => microtime(true),
        ];
        if (isset($this->response->error)) {
            $error['code'] = $this->response->error->error_code;
            $error['message'] = $this->response->error->error_msg;
            if ($this->extendedErrors) {
                $error['params'] = $this->response->error->request_params;
            }
        } else {
            $error['code'] = -1;
            $error['message'] = 'Unexpected error';
        }

        return $error;
    }

    /**
     * Метод добавления ошибки
     * @param array $error
     * @return int
     */
    protected function addError(array $error): int
    {
        $counter = array_push($this->errors, $error);
        return ($counter - 1);
    }

    /**
     * Регистрирует выполнения запроса в логе
     * @param $errorIndex
     * @param $elapsedTime
     * @return int
     */
    protected function logResponse($errorIndex, $elapsedTime): int
    {
        $logNote = [
            'method' => $this->methodFullName,
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
    protected function addLogNote($note): int
    {
        $counter = array_push($this->log, $note);
        return ($counter - 1);
    }

    /**
     * Учёт результата выполнения запроса для статистики
     * @param $result
     * @param $elapsedTime
     */
    protected function addStatistic($result, $elapsedTime)
    {
        if (!isset($this->statistic[$this->methodFullName])) $this->statistic[$this->methodFullName] = [
            'successCounter' => 0,
            'successTime' => 0,
            'failCounter' => 0,
            'failTime' => 0,
        ];
        if ($result) {
            $this->statistic[$this->methodFullName]['successCounter']++;
            $this->statistic[$this->methodFullName]['successTime'] += $elapsedTime;
        } else {
            $this->statistic[$this->methodFullName]['failCounter']++;
            $this->statistic[$this->methodFullName]['failTime'] += $elapsedTime;
        }
    }

    /**
     * Магический метод вызова метода API, выполняющий его
     * @param $methodName
     * @param $arguments
     * @return self
     */
    public function __call($methodName, $arguments): self
    {
        $this->methodName = $methodName;
        $this->methodParams = $arguments[0] ?? [];

        return $this->execute();
    }
}