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
