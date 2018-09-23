<?php

namespace avtomon;

use Ratchet\{
    MessageComponentInterface, ConnectionInterface
};

class ChatException extends CustomException
{
    /**
     * Идентификатор пользователя
     *
     * @var int
     */
    protected $userId;

    /**
     * Идентификатор беседы
     *
     * @var int
     */
    protected $dialogId;

    /**
     * Объект подключения
     *
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     * Конструктор
     *
     * @param string $message - сообщение исключения
     * @param ConnectionInterface|null $conn - объект подключения
     * @param int $dialogId - идентификатор беседы
     * @param int $userId - идентификатор пользователя
     * @param int $code - код ошибки
     * @param \Throwable|null $previous - предыдущее исключение
     */
    public function __construct(
        string $message = "",
        ConnectionInterface $conn = null,
        int $dialogId = 0,
        int $userId = 0,
        int $code = 0,
        \Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);

        $this->userId = $userId;
        $this->dialogId = $dialogId;
        $this->connection = $conn;
    }

    /**
     * Вернуть идентификатор пользователя
     *
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Вернуть идентификатор беседы
     *
     * @return int
     */
    public function getDialogId(): int
    {
        return $this->dialogId;
    }

    /**
     * Вернуть объект подключения
     *
     * @return ConnectionInterface
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}

class Chat implements MessageComponentInterface
{
    /**
     * Теги, которые не надо убирать из сообщений
     *
     * @var string
     */
    private $allowedTags = '<img><iframe>';

    /**
     * Поключенные клиенты
     *
     * @var array
     */
    protected $clients = [];

    /**
     * Сессии подключенных пользователей
     *
     * @var array
     */
    protected $sessions = [];

    /**
     * Активные беседы
     *
     * @var array
     */
    protected $dialogs = [];

    /**
     * Куда писать логи
     *
     * @var string
     */
    private $logPath = '/var/www/payment_system/logs/chat.log';

    /**
     * Функция записи сообщения в хранилище, в случае если все участники беседы онлайн
     *
     * @var callable|null
     */
    private $plainSave;

    /**
     * Функция записи сообщения в хранилище, в случае если не все участники беседы онлайн
     *
     * @var callable|null
     */
    private $unreadSave;

    /**
     * Эта функция выполняется перед отправкой сообщения
     *
     * @var callable|null
     */
    private $beforeSend;

    /**
     * А эта после отправки сообщения
     *
     * @var callable|null
     */
    private $afterSend;

    /**
     * Путь к Redis-сокету
     *
     * @var string
     */
    private $redisSocket;

    /**
     * Конструктор
     *
     * @param string $redisSocket - путь к сокету Redis
     * @param callable $plainSave - колбэк сохранения сообщения
     * @param callable $unreadSave - колбэк сохранения сообщения и информации о непрочитанных
     * @param callable $beforeSend - колбэк выполняемый перед отправкой сообщения
     * @param callable $afterSend - колбэк выполняемый после отправки сообщения
     * @param string $allowedTags - теги, которые не надо убирать из сообщений
     */
    public function __construct(
        string $redisSocket,
        $plainSave = null,
        $unreadSave = null,
        $beforeSend = null,
        $afterSend = null,
        string $allowedTags = null
    )
    {
        $this->plainSave = $plainSave;
        $this->unreadSave = $unreadSave;
        $this->beforeSend = $beforeSend;
        $this->afterSend = $afterSend;
        $this->redisSocket = $redisSocket;

        if (!is_null($allowedTags)) {
            $this->allowedTags = $allowedTags;
        }
    }

    /**
     * Колбэк события установки соединения
     *
     * @param ConnectionInterface $conn - объект соединения
     *
     * @throws \Throwable
     */
    public function onOpen(ConnectionInterface $conn)
    {
        try {
            if ((!empty($conn->httpRequest) && empty($query = $conn->httpRequest->getUri()->getQuery())) || empty($sessionId = explode('=', $query)[1])) {
                throw new ChatException('Не передан идентификатор сессии', $conn);
            }

            if (!($redis = RedisSingleton::create($this->redisSocket))) {
                throw new ChatException('Не удалось подключиться к Redis', $conn);
            }

            if (!($session = $redis->get("PHPREDIS_SESSION:$sessionId"))) {
                throw new ChatException('Сессия не найдена в Redis', $conn);
            }

            $session = preg_replace("/;*(?:i|s:\d+):(.+?);(?:i|s:\d+):(.+?);/i", '$1:$2,', $session);
            $session = preg_replace("/User\|a:\d+:/i", '', $session);
            $this->toLog(print_r($session, true));
            if (!($session = json_decode(str_replace(',}', '}', $session), true))) {
                throw new ChatException('Не удалось декодировать данные сессии', $conn);
            }

            if (empty($session['user_id'])) {
                throw new ChatException('Не удалось получить идентификатор пользователя', $conn);
            }
        } catch (\Throwable $e) {
            $conn->close();
            throw $e;
        }

        $this->sessions[$session['user_id']] = $session;
        $this->clients[$session['user_id']] = $conn;
    }

    /**
     * Колбэк на получение сервером нового сообщения
     *
     * @param ConnectionInterface $from - с какого соединения пришло сообщения
     * @param string $message - сообщение
     *
     * @throws ChatException
     */
    public function onMessage(ConnectionInterface $from, $message)
    {
        if (!($messageArray = json_decode($message, true))) {
            throw new ChatException('Сообщение должно быть передано в формате JSON', $from);
        }

        if (!($userId = array_keys($this->clients, $from, true)[0])) {
            throw new ChatException('Подключние не найдено', $from);
        }

        if (empty($this->sessions[$userId])) {
            throw new ChatException('Сессия не найдена', $from);
        }

        if (empty($messageArray['dialogId'])) {
            throw new ChatException('Не задан идентификатор беседы', $from);
        }

        if (!($dialogInfo = $this->getDialogInfo($messageArray['dialogId']))) {
            throw new ChatException('Беседа с таким идентификатором еще не была создана', $from);
        }

        if (!in_array($userId, $dialogInfo['users'])) {
            throw new ChatException("Вам не разрешено писать в эту беседу", $from);
        }

        $messageArray['from'] = $this->sessions[$userId];

        $messageArray['text'] = $this->sanitizeText($messageArray['text']);

        if (empty($messageArray['text'])) {
            throw new ChatException('Передано пустое сообщение', $from);
        }

        if ($this->beforeSend) {
            ($this->beforeSend)($messageArray);
        }

        foreach ($dialogInfo['users'] as $userId) {
            if (!empty($this->clients[$userId])) {
                $this->clients[$userId]->send(json_encode($messageArray, JSON_UNESCAPED_UNICODE));
            } else {
                $unreadUsers[] = $userId;
            }
        }

        if (empty($unreadUsers)) {
            ($this->plainSave)($messageArray);
        } else {
            ($this->unreadSave)($messageArray, $unreadUsers);
        }

        if ($this->afterSend) {
            ($this->afterSend)($messageArray);
        }
    }

    /**
     * Колбэк на событие закрытия соединения
     *
     * @param ConnectionInterface $conn - объект соединения
     */
    public function onClose(ConnectionInterface $conn)
    {
        $userId = array_keys($this->clients, $conn)[0] ?? null;
        if ($userId) {
            unset($this->clients[$userId], $this->sessions[$userId]);
        }
    }

    /**
     * Колбэк на ошибку
     *
     * @param ConnectionInterface $conn - объект соединения
     * @param \Exception $e - объект ошибки
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
        throw new $e;
    }

    /**
     * Записать текст в лог
     *
     * @param string $message - текст
     */
    public function toLog(string $message)
    {
        file_put_contents($this->logPath, $message);
    }

    /**
     * Получить информацию о беседе
     *
     * @param int $id - идентификатор беседы
     *
     * @return mixed|null
     */
    protected function getDialogInfo(int $id)
    {
        if (empty($this->dialogs[$id])) {
            $result = Message::getDialogInfo(['id' => $id]);
            if (!$result) {
                return null;
            }

            $result = $result->getFirstResult();
            $result['users'] = json_decode($result['users'], true);
            $this->dialogs = array_merge($this->dialogs, [$id => $result]);
        }

        return $this->dialogs[$id];
    }

    /**
     * Очистить текст сообщения от неразрешенных тегов
     *
     * @param string $text - текст сообщения
     *
     * @return string
     */
    protected function sanitizeText(string $text)
    {
        return strip_tags($text, $this->allowedTags);
    }

    /**
     * Обработчик исключений
     *
     * @param \Throwable $e - объект исключения/ошибки
     */
    public function handleError(ChatException $e)
    {
        if (empty($e->getMessage())) {
            return;
        }

        if (empty($e->getConnection()) && empty($e->getUserId()) && empty($e->getDialogId())) {
            $this->toLog($e->getMessage());
        }

        $message = [
            'text' => $e->getMessage(),
            'type' => 'error',
            'from' => 'system'
        ];

        if ($e->getConnection()) {
            $e->getConnection()->send(json_encode($message, JSON_UNESCAPED_UNICODE));
            return;
        }

        if ($userId = $e->getUserId() && !empty($this->clients[$e->getUserId()])) {
            $this->clients[$e->getUserId()]->send(json_encode($message, JSON_UNESCAPED_UNICODE));
            return;
        }

        if ($e->getDialogId() && !empty($this->dialogs[$e->getDialogId()])) {
            foreach ($this->dialogs[$e->getDialogId()]['users'] as $userId) {
                $this->clients[$userId]->send(json_encode($message, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}