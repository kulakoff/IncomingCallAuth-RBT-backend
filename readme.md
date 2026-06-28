# Backend авторизации по исходящему звонку для RBT в виде Custom Backend

## Incoming Auth

В проект вынесена кастомизация обработки входящих звонков для контекста `from-provider`.

Номер телефона, который передаётся пользователю для подтверждения, задаётся отдельно в серверной конфигурации:

- `/opt/rbt/server/config/config.json`

Пример настройки:

```json
"isdn": {
    "backend": "custom",
    "confirm_number": "+1234567890",
    "confirm_method": "outgoingCall"
}
```

Параметр `confirm_number` определяет номер, который используется в сценарии подтверждения.

## SIP Provider Setup

Для работы подтверждения нужно настроить SIP trunk провайдера, через который входящий вызов будет попадать в Asterisk в контекст `from-provider`.

Файл настройки:

- `rbt/asterisk/trunks/provider.conf`

В шаблоне конфигурации нужно заменить тестовые значения на реальные данные провайдера:

- `SIP_USER` - логин SIP-аккаунта
- `SIP_USER_PASS` - пароль SIP-аккаунта
- `SIP_SERVER_ADDRESS` - адрес SIP-сервера провайдера
- `50605` - локальный UDP-порт Asterisk, при необходимости можно изменить

Важно:

- в секции `[provider-endpoint]` должен быть указан `context=from-provider`;
- именно этот контекст направляет входящий звонок в кастомный обработчик `handleIncomingCall`.

Пример настройки:

```ini
[transport-udp-provider]
type=transport
protocol=udp
bind=0.0.0.0:50605

[provider-reg]
type=registration
transport=transport-udp-provider
outbound_auth=provider-auth
retry_interval=60
expiration=300
auth_rejection_permanent=yes
contact_user=SIP_USER
server_uri=sip:SIP_SERVER_ADDRESS:5060
client_uri=sip:SIP_USER@SIP_SERVER_ADDRESS:5060

[provider-auth]
type=auth
auth_type=userpass
username=SIP_USER
password=SIP_USER_PASS

[provider-endpoint]
type=endpoint
transport=transport-udp-provider
context=from-provider
disallow=all
allow=alaw,ulaw
outbound_auth=provider-auth
aors=provider-aor
from_domain=SIP_SERVER_ADDRESS
from_user=SIP_USER
sdp_owner=SIP_USER
direct_media=no
ice_support=no
send_rpid=yes
rtp_symmetric=yes
force_rport=yes
timers=no

[provider-aor]
type=aor
contact=sip:SIP_SERVER_ADDRESS:5060

[provider-identify]
type=identify
endpoint=provider-endpoint
match=SIP_SERVER_ADDRESS
```

Файл кастомизации:

- `rbt/asterisk/custom/incoming_auth.lua`

Подключение кастомизации выполняется в:

- `rbt/asterisk/config.lua`

```lua
custom = {
    "incoming_auth"
}
```

### Что делает `incoming_auth.lua`

Кастомный файл:

- переопределяет функцию `handleIncomingCall`;
- переназначает маршрут `extensions["from-provider"]["_X."] = handleIncomingCall`.

Это позволяет изменить логику обработки входящего звонка без правки основного `extensions.lua`.

### Логика обработки звонка

При входящем звонке обработчик:

1. отвечает на вызов через `app.Answer()`;
2. получает номер из `channel.CALLERID("num"):get()`;
3. проверяет, что номер определился:
   - значение не `nil`;
   - значение не пустое;
   - строка содержит хотя бы одну цифру;
4. если номер невалидный, пишет лог `handleIncomingCall | caller id is not valid` и завершает вызов;
5. если номер валидный, берёт последние 5 символов номера;
6. сохраняет ключ `incoming_call_<last5>` в Redis на 300 секунд;
7. завершает вызов через `app.Hangup()`.

### Пример обработчика

```lua
function handleIncomingCall(context, extension)
    app.Answer()

    local callerId = channel.CALLERID("num"):get()
    if callerId == nil or callerId == "" or not callerId:match("%d") then
        logDebug("handleIncomingCall | caller id is not valid")
        app.Hangup()
        return
    end

    logDebug("handleIncomingCall | incoming call: " .. callerId)

    local lastFiveDigits = string.sub(callerId, -5)
    local redisKey = "incoming_call_" .. lastFiveDigits

    redis:setex(redisKey, 300, os.time())
    app.Hangup()
end

extensions["from-provider"] = {
    ["_X."] = handleIncomingCall
}
```
