<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class User3Model extends User2Model {
    protected $maxGoldEveryDay = 20000;

    /**
     * 获取用户信息/添加新用户
     * @param type $deviceId
     * @param type $deviceInfo
     * @return type
     */
    public function getUserInfo($deviceId, $deviceInfo = array()) {
        $whereArr = $data = array();
        $whereArr[] = 1;
        $whereArr[] = 'device_id = :device_id';
        $data['device_id'] = $deviceId;
        
        $where = implode(' AND ', $whereArr);
        $sql = 'SELECT * FROM t_user WHERE ' . $where;
        $userInfo = $this->db->getRow($sql, $data);

        if (isset($deviceInfo['versionCode']) && $deviceInfo['versionCode'] >= 230) {
            if ($deviceInfo['versionCode'] >= 232) {
                $newInfo = array('activity_award_min' => 88000, 'activity_status' => 1);
            } else {
                $newInfo = array('activity_award_min' => 5000, 'activity_status' => 1);
            }
        } else {
            $sql = 'SELECT activity_award_min, activity_status FROM t_activity WHERE activity_type = "newer"';
            $newInfo = $this->db->getRow($sql);
        }
        if ($userInfo) {
            $goldInfo = $this->getGold($userInfo['user_id']);
            if (isset($deviceInfo['umengToken']) && $deviceInfo['umengToken']) {
                $umengClass = new Umeng();
                $score = $umengClass->verify($deviceInfo['umengToken']) ?: 0;
                $sql = 'UPDATE t_user SET umeng_token = ?, umeng_score = ? WHERE user_id = ?';
                $this->db->exec($sql, $deviceInfo['umengToken'], $score, $userInfo['user_id']);
            }
            $sql = 'SELECT COUNT(withdraw_id) FROM t_withdraw WHERE withdraw_amount = 1 AND user_id = ? AND withdraw_status = "success"';
            $isOneCashed = $this->db->getOne($sql, $userInfo['user_id']);
            $sql = 'SELECT COUNT(withdraw_id) FROM t_withdraw WHERE withdraw_amount = 0.3 AND user_id = ? AND withdraw_status = "success"';
            $isCashed03 = $this->db->getOne($sql, $userInfo['user_id']);
            $receiveNewer = $this->model->gold->existSource($userInfo['user_id'], 'newer');
            return  array(
                'userId' => $userInfo['user_id'],
                'accessToken' => $userInfo['access_token'],
                'currentGold' => $goldInfo['currentGold'],
                'nickname' => $userInfo['nickname'],
                'sex' => $userInfo['sex'],
                'province' => $userInfo['province'],
                'city' => $userInfo['city'],
                'country' => $userInfo['country'],
                'headimgurl' => $userInfo['headimgurl'],
                'phone' => $userInfo['phone_number'],
                'isOneCashed' => $isOneCashed ? 1 : 0,
                'invitedCode' => $userInfo['invited_code'],
                'appSource' => ($userInfo['reyun_app_name'] ?: $userInfo['app_name']) . '_' . ($userInfo['compaign_id'] ?? ''),// 渠道号 来源热云
                'compaignId' => $userInfo['compaign_id'],// 子渠道号 来源热云
                'newerGold' => $receiveNewer ? 0 : $newInfo['activity_award_min'],
                'withdrawTime' => (strtotime($userInfo['create_time']) + 600) * 1000,
                'serverTime' => time() * 1000,
                'isCashed03' => $isCashed03 ? 1 : 0
            );
        } else {
            $invitedClass = new Invited();
            $invitedCode = $invitedClass->createCode();
            $reyunAppName = $this->reyunAppName($deviceInfo['IMEI'] ?? '', $deviceInfo['OAID'] ?? '', $deviceInfo['AndroidId'] ?? '', $deviceInfo['mac'] ?? '');

            $score = 0;
            if (isset($deviceInfo['umengToken']) && $deviceInfo['umengToken']) {
                $umengClass = new Umeng();
                $score = $umengClass->verify($deviceInfo['umengToken']) ?: 0;
            }

            $nickName = '游客' . substr($deviceId, -2) . date('Ymd');//游客+设备号后2位+用户激活日期
            $accessToken = md5($deviceId . time());
            $sql = 'INSERT INTO t_user SET access_token = ?, device_id = ?, nickname = ?, app_name = ?, reyun_app_name = ?,  VAID = ?, AAID = ?, OAID = ?, brand = ?, model = ?, SDKVersion = ?, AndroidId = ?, IMEI = ?, MAC = ?, invited_code = ?, umeng_token = ?, umeng_score = ?, compaign_id = ?';
            $this->db->exec($sql, $accessToken, $deviceId, $nickName, $deviceInfo['source'] ?? '', $reyunAppName['app_name'] ?? '', $deviceInfo['VAID'] ?? '', $deviceInfo['AAID'] ?? '', $deviceInfo['OAID'] ?? '', $deviceInfo['brand'] ?? '', $deviceInfo['model'] ?? '', $deviceInfo['SDKVersion'] ?? '', $deviceInfo['AndroidId'] ?? '', $deviceInfo['IMEI'] ?? '', $deviceInfo['MAC'] ?? '', $invitedCode, $deviceInfo['umengToken'] ?? '', $score, $reyunAppName['compaign_id'] ?? '');
            $userId = $this->db->lastInsertId();

            if (isset($reyunAppName['log_id'])) {
                $sql = 'UPDATE t_reyun_log SET user_id = ? WHERE log_id = ?';
                $this->db->exec($sql, $userId, $reyunAppName['log_id']);
            }

            $gold = 0;
            return  array(
                'userId' => $userId,
                'accessToken' => $accessToken,
                'currentGold' => $gold,
                'nickname' => $nickName,
                'award' => $gold,
                'invitedCode' => $invitedCode,
                'appSource' => ($reyunAppName['app_name'] ?? ($deviceInfo['source'] ?? '')) . '_' . ($reyunAppName['compaign_id'] ?? ''),
                'compaignId' => $reyunAppName['compaign_id'] ?? '',// 子渠道号 来源热云
                'newerGold' => $newInfo['activity_status'] ? $newInfo['activity_award_min'] : 0,
                'withdrawTime' => (time() + 600) * 1000,
                'serverTime' => time() * 1000,
                'isCashed03' => 0
            );
        }
    }

}