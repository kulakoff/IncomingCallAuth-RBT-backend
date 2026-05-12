<?php

    /**
     * backends isdn namespace
     */

    namespace backends\isdn {

        /**
         * Custom variant of flash calls and sms sending for Videogorod
         */

        require_once __DIR__ . "/../.traits/push.php";
        require_once __DIR__ . "/../.traits/sms.php";
        require_once __DIR__ . "/../.traits/incoming.php";

        class custom extends isdn {
            use push, sms, incoming;

            function checkIncoming($id) {
                global $redis;
                $last5 = substr($id, -5);
                $redisKey = 'incoming_call_' . $last5;
                $result = $redis->get($redisKey);
                
                if ($result) {
                    return true;
                }

                return false;
            }
        }
    }
