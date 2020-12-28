<?php 

Class User2Controller extends UserController {
    
    /**
     * 获取用户信息
     * 301 无效设备号
     * @return \ApiReturn
     */
    public function infoAction() {
        if (isset($this->inputData['deviceId'])) {
            $userInfo = $this->model->user2->getUserInfo($this->inputData['deviceId'], $this->inputData['userDeviceInfo'] ?? array());
            if (isset($this->inputData['userDeviceInfo']['source']) && isset($this->inputData['userDeviceInfo']['versionCode'])) {
                $sql = 'SELECT ad_status FROM t_version_ad WHERE version_id = ? AND app_name = ?';
                $userInfo['adStatus'] = $this->db->getOne($sql, $this->inputData['userDeviceInfo']['versionCode'], $this->inputData['userDeviceInfo']['source']) ?: 0;
            } else {
                $userInfo['adStatus'] = 0;
            }
            $this->model->user2->todayFirstLogin($userInfo['userId']);
            $this->model->user2->lastLogin($userInfo['userId']);
            unset($userInfo['userId']);
            return new ApiReturn($userInfo);
        } else {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
    }
    
    /**
     * 获取手机验证码
     * @return \ApiReturn|\apiReturn
     */
    public function sendSmsCodeAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (!isset($this->inputData['phone'])) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $phoneInfo = $this->model->user2->userInfo($userId, 'phone_number');
        if ($phoneInfo) {
            return new ApiReturn('', 301, '您已绑定手机号');
        }
        $sql = 'SELECT COUNT(*) FROM t_user WHERE phone_number = ?';
        $phoneOne = $this->db->getOne($sql, $this->inputData['phone']);
        if ($phoneOne) {
            return new ApiReturn('', 304, '您绑定的手机号已存在');
        }
        
        $sql = 'SELECT create_time FROM t_sms_code WHERE user_id = ?';
        $smsInfo = $this->db->getOne($sql, $userId);
        if ($smsInfo && strtotime($smsInfo) > strtotime('-1 minutes') ) {
            return new ApiReturn('', 302, '获取频繁，请稍后再试');
        }
        $code = (string) rand(100000, 999999);
        $sms = new Sms();
        $return = $sms->sendMessage($this->inputData['phone'], $code);
        if ($return) {
            $sql = 'REPLACE INTO t_sms_code SET user_id = ?, code_value = ?';
            $this->db->exec($sql, $userId, $code);
            return new ApiReturn('');
        } else {
            //insert error log
            return new ApiReturn('', 303, '抱歉，短信发送失败');
        }
    }
    
    /**
     * 绑定手机号
     * @return \ApiReturn|\apiReturn
     */
    public function buildPhoneAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (!isset($this->inputData['phone']) || !isset($this->inputData['smsCode'])) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $phoneInfo = $this->model->user2->userInfo($userId, 'phone_number');
        if ($phoneInfo) {
            return new ApiReturn('', 301, '您已绑定手机号');
        }
        $sql = 'SELECT COUNT(*) FROM t_user WHERE phone_number = ?';
        $phoneOne = $this->db->getOne($sql, $this->inputData['phone']);
        if ($phoneOne) {
            return new ApiReturn('', 304, '您绑定的手机号已存在');
        }
        
        $sql = 'SELECT create_time FROM t_sms_code WHERE user_id = ? AND code_value = ?';
        $codeInfo = $this->db->getOne($sql, $userId, $this->inputData['smsCode']);
        if ($codeInfo) {
            if (strtotime($codeInfo) < strtotime("-5 minutes")) {
                return new ApiReturn('', 305, '验证码过期，请重新获取');
            }
            $sql = 'UPDATE t_user SET phone_number = ?, nickname = ? WHERE user_id = ?';
            $this->db->exec($sql, $this->inputData['phone'], substr_replace($this->inputData['phone'], '****', 3, 4), $userId);
            $return = array();
            $sql = 'DELETE FROM t_sms_code WHERE user_id = ?';
            $this->db->exec($sql, $userId);
            return new ApiReturn($return);
        } else {
            return new ApiReturn('', 306, '验证码错误，请重新输入');
        }
    }
    
    /**
     * 获取版本信息
     * @return \ApiReturn|\apiReturn
     */
    public function getVersionAction () {
        $sql = 'SELECT * FROM t_version ORDER BY version_id DESC LIMIT 1';
        $versionInfo = $this->db->getRow($sql);
        return new ApiReturn(array(
            'versionCode' => $versionInfo['version_id'],
            'versionName' => $versionInfo['version_name'],
            'forceUpdate' => $versionInfo['is_force_update'],
            'apkUrl' => HOST_OSS . $versionInfo['version_url'],
            'updateLog' => $versionInfo['version_log'],
            'needUpdateVersionCode' => $versionInfo['need_update_id'],
        ));
    }
    
    /**
     * 获取运营位列表
     * @return \ApiReturn|\apiReturn
     */
    public function getAdAction() {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        //start 首页底部  top 任务页 头部  new 任务页新手任务  daily 任务页日常任务  my 我的页面 右上角  dogs 我的页面狗狗世界导流
        $adCount = array('start' => 3, 'top' => 4, 'new' => 0, 'daily' => 0, 'my' => 1, 'dogs' => 0, 'start_2' => 3, 'start_left' => 1, 'task_h' => 0);
        if (!isset($this->inputData['location']) || !in_array($this->inputData['location'], array_keys($adCount))) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $sql = 'SELECT advertise_id, advertise_type, advertise_name, advertise_subtitle, CONCAT(?, advertise_image) img, advertise_url, advertise_validity_type, advertise_validity_type, advertise_validity_start, advertise_validity_end, advertise_validity_length
                FROM t_advertise_old
                WHERE advertise_location = ?
                AND advertise_status = 1
                ORDER BY advertise_sort DESC';
        $advertiseList = $this->db->getAll($sql, HOST_OSS, $this->inputData['location']);
        $returnList = $tempArr = array();
        $adLimitCount = $adCount[$this->inputData['location']];
        $todayTime = time();
        $taskClass = new Task();
        $userCreateTime = $this->model->user2->userInfo($userId, 'create_time');
        $isAllBuild = 'new' == $this->inputData['location'] ? TRUE : FALSE;
        foreach ($advertiseList as $advertiseInfo) {
            if ($adLimitCount && $adLimitCount <= count($returnList)) {
                break;
            }
            switch ($advertiseInfo['advertise_validity_type']) {
                case 'fixed':
                    if (($todayTime < strtotime($advertiseInfo['advertise_validity_start'] . ' 00:00:00')) || ($todayTime > strtotime($advertiseInfo['advertise_validity_end'] . ' 23:59:59'))) {
                        continue 2;
                    }
                    break;
                case 'limited':
                    $adEndTime = strtotime('+ ' . $advertiseInfo['advertise_validity_length'] . 'days', strtotime($userCreateTime));
                    if ($adEndTime < $todayTime) {
                        continue 2;
                    }
                    break;
            }
            if (in_array($advertiseInfo['advertise_id'], array(2, 10, 24))) {
                $sql = 'SELECT access_token FROM t_user WHERE user_id = ?';
                $accessToken = $this->db->getOne($sql, $userId);
                $advertiseInfo['advertise_url'] .= '&userId=' . $accessToken;
            }
            $tempArr = array('type' => $advertiseInfo['advertise_type'],
                'name' => $advertiseInfo['advertise_name'],
                'subName' => $advertiseInfo['advertise_subtitle'],
                'img' => $advertiseInfo['img'],
                'url' => $advertiseInfo['advertise_url']);
            if ('task' == $advertiseInfo['advertise_type']) {
                $tempArr['info'] = $taskClass->getTask($advertiseInfo['advertise_url'], $userId);
                if ($isAllBuild) {
                    $isAllBuild = $tempArr['info']['isBuild'] ? TRUE : FALSE;
                }
            }
            $returnList[] = $tempArr;
        }
        if ($isAllBuild) {
            $returnList = [];
        }
        return new ApiReturn($returnList);
    }
    
    /**
     * 绑定支付宝
     * @return \ApiReturn|\apiReturn
     */
    public function buildAlipayAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (!isset($this->inputData['account']) || !isset($this->inputData['name']) || !isset($this->inputData['idCard'])) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $sql = 'UPDATE t_user SET alipay_account = ?, alipay_name = ?, id_card = ? WHERE user_id = ?';
        $this->db->exec($sql, $this->inputData['account'], $this->inputData['name'], $this->inputData['idCard'], $userId);
        return new ApiReturn('');
    }
    
    /**
     * 获取提现信息
     * @return \ApiReturn|\apiReturn
     */
    public function getWithdrawAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        $sql = 'SELECT alipay_account account, phone_number phone, IF(ali_user_id=0,0,1) ali, unionid FROM t_user WHERE user_id = ?';
        $userInfo = $this->db->getRow($sql, $userId);
        if ($userInfo['account']) {
            $userInfo['account'] = substr_replace($userInfo['account'], '****', 3, 4);
        }
        if ($userInfo['phone']) {
            $userInfo['phone'] = substr_replace($userInfo['phone'], '****', 3, 4);
        }
        $userInfo['gearList'] = array();

        $versionCode = $_SERVER['HTTP_VERSION_CODE'] ?? 0;
        if ($versionCode >= 232) {
            // 修改为0.3  50  100  150  200 20201014
            foreach (array(0.3, 100, 150, 200) as $withdraw) {
                if (0.3 == $withdraw) {
                    $sql = 'SELECT COUNT(withdraw_id) FROM t_withdraw WHERE (withdraw_amount = 0.3 OR withdraw_amount = 1) AND user_id = ? AND (withdraw_status = "pending" OR withdraw_status = "success")';
                    if ($this->db->getOne($sql, $userId)) {
                        continue;
                    }
                }
                if (50 == $withdraw) {
                    $sql = 'SELECT COUNT(withdraw_id) FROM t_withdraw WHERE withdraw_amount = 50 AND user_id = ? AND (withdraw_status = "pending" OR withdraw_status = "success")';
                    if ($this->db->getOne($sql, $userId)) {
                        continue;
                    }
                }
                $temp = array('values' => $withdraw, 'nums' => $withdraw * 10000);
                $userInfo['gearList'][] = $temp;
            }
        } else {
            foreach (array(1, 5, 15, 30, 50, 100) as $withdraw) {
                if (in_array($withdraw, array(1, 5))) {
                    $sql = 'SELECT COUNT(withdraw_id) FROM t_withdraw WHERE withdraw_amount = ? AND user_id = ? AND (withdraw_status = "pending" OR withdraw_status = "success")';
                    if ($this->db->getOne($sql, $withdraw, $userId)) {
                        continue;
                    }
                }
                $userInfo['gearList'][] = array('values' => $withdraw, 'nums' => $withdraw * 10000);
            }
        }

        $sql = 'SELECT withdraw_id id, withdraw_status status, withdraw_amount amount, unix_timestamp(create_time) * 1000 createTime, unix_timestamp(change_time) * 1000 changeTime, withdraw_user_read, can_withdraw canWithdraw FROM t_withdraw WHERE user_id = ? AND (withdraw_status = "pending" OR withdraw_status = "success") ORDER BY withdraw_id DESC';
        $withdrawInfo = $this->db->getRow($sql, $userId);
        $userInfo['withdrawInfo'] = (object) array();
        if ($withdrawInfo && (($withdrawInfo['status'] == 'pending') || ($withdrawInfo['status'] == 'success' && !$withdrawInfo['withdraw_user_read']))) {
            unset($withdrawInfo['withdraw_user_read']);
            $userInfo['withdrawInfo'] = $withdrawInfo;
        }
        $userInfo['serverTime'] = time() * 1000;
        return new ApiReturn($userInfo);
    }
    
    /**
     * 绑定微信
     * @return \ApiReturn|\apiReturn
     */
    public function buildWechatAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (!isset($this->inputData['unionid'])) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $unionInfo = $this->model->user2->userInfo($userId, 'unionid');
        if ($unionInfo) {
            return new ApiReturn('', 307, '您已绑定微信');
        }
        $sql = 'SELECT COUNT(*) FROM t_user WHERE unionid = ?';
        $unionOne = $this->db->getOne($sql, $this->inputData['unionid']);
        if ($unionOne) {
            return new ApiReturn('', 308, '您绑定的微信号已存在');
        }
        
        $sql = 'UPDATE t_user SET openid = ?, nickname = ?, language = ?, sex = ?, province = ?, city = ?, country = ?, headimgurl = ?, unionid = ? WHERE user_id = ?';
        $this->db->exec($sql, $this->inputData['openid'] ?? '', $this->inputData['nickname'] ?? '', $this->inputData['language'] ?? '', $this->inputData['sex'] ?? 0, $this->inputData['province'] ?? '', $this->inputData['city'] ?? '', $this->inputData['country'] ?? '', $this->inputData['headimgurl'] ?? '', $this->inputData['unionid'], $userId);
        $return = array();
        $awardInfo = $this->model->gold->existSource($userId, 'wechat');
        if (!$awardInfo) {
            $sql = 'SELECT activity_award_min, activity_status FROM t_activity WHERE activity_type = "wechat"';
            $goldInfo = $this->db->getRow($sql);
            if (!$goldInfo['activity_status']) {
                $return['award'] = 0;
            } else {
                $this->model->gold->updateGold(array('user_id' => $userId, 'gold' => $goldInfo['activity_award_min'], 'source' => 'wechat', 'type' => 'in'));
                $return['award'] = $goldInfo['activity_award_min'];
            }
        }
        return new ApiReturn($return);
    }
    
    /**
     * 填写邀请码
     * @return \ApiReturn|\apiReturn
     */
    public function buildInvitedAction () {
        $invitedId = $this->model->user2->verifyToken();
        if ($invitedId instanceof apiReturn) {
            return $invitedId;
        }
        if (!isset($this->inputData['invitedCode'])) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $sql = 'SELECT COUNT(*) FROM t_user_invited WHERE invited_id = ?';
        $invitedInfo = $this->db->getOne($sql, $invitedId);
        if ($invitedInfo) {
            return new ApiReturn('', 309, '您已填写过邀请码');
        }
        $sql = 'SELECT user_id, create_time FROM t_user WHERE invited_code = ?';
        $userInfo = $this->db->getRow($sql, $this->inputData['invitedCode']);
        if (!$userInfo) {
            return new ApiReturn('', 310, '邀请码无效，请重新输入');
        }
        $invitedCreate = $this->model->user2->userInfo($invitedId, 'create_time');
        if (strtotime($invitedCreate) <= strtotime($userInfo['create_time'])) {
            return new ApiReturn('', 311, '验证码无效，请填写比您先注册的用户的邀请码');//
        }
        $unionInfo = $this->model->user2->userInfo($invitedId, 'unionid');
        if (!$unionInfo) {
            return new ApiReturn('', 312, '请先绑定微信后，再填写邀请码');
        }
        $sql = 'INSERT INTO t_user_invited SET user_id = ?, invited_id = ?';
        $this->db->exec($sql, $userInfo['user_id'], $invitedId);
        
        $relationId = $this->db->lastInsertId();
        
        $return = array();
        $awardInfo = $this->model->gold->existSource($userInfo['user_id'], 'invited');
        if (!$awardInfo) {
            $sql = 'SELECT activity_award_min, activity_status FROM t_activity WHERE activity_type = "invited"';
            $goldInfo = $this->db->getRow($sql);
            if (!$goldInfo['activity_status']) {
                $return['award'] = 0;
            } else {
                $this->model->gold->updateGold(array('user_id' => $invitedId, 'gold' => $goldInfo['activity_award_min'], 'source' => 'invited', 'type' => 'in', 'relation_id' => $relationId));
                $return['award'] = $goldInfo['activity_award_min'];
            }
            
            $sql = 'SELECT activity_award_min, activity_status FROM t_activity WHERE activity_type = "do_invite"';
            $gold = $this->db->getRow($sql);
            if ($gold['activity_status']) {
                $this->model->gold->updateGold(array('user_id' => $userInfo['user_id'], 'gold' => $gold['activity_award_min'], 'source' => 'do_invite', 'type' => 'in', 'relation_id' => $relationId));
            }
        }
        return new ApiReturn($return);
    }

    /**
     * 用户反馈
     * @return ApiReturn
     */
    public function feedbackAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (!isset($this->inputData['content']) && $this->inputData['content']) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        //判断多次提交需要超过多久
        $sql = 'SELECT create_time FROM t_user_feedback WHERE user_id = ? ORDER BY feedback_id DESC';
        $lastUpload = $this->db->getOne($sql, $userId);
        if ($lastUpload && (time() - strtotime($lastUpload) < 600)) {
            return new ApiReturn('', 315, '上传太频繁');
        }
        
        foreach (array('image1', 'image2', 'image3') as $image) {
            if (isset($this->inputData[$image])) {
                $$image = $this->uploadImage($this->inputData[$image]);
                if ($$image instanceof apiReturn) {
                    return $$image;
                }
            }
        }
        
        $sql = 'INSERT INTO t_user_feedback SET user_id = :user_id, content = :content, phone = :phone, image_1 = :image_1, image_2 = :image_2, image_3 = :image_3';
        $this->db->exec($sql, array(
            'user_id' => $userId,
            'content' => $this->inputData['content'],
            'phone' => $this->inputData['phone'] ?? 0,
            'image_1' => $image1 ?? '',
            'image_2' => $image2 ?? '',
            'image_3' => $image3 ?? '',
        ));
        return new ApiReturn();
    }

    /**
     * 绑定支付宝信息
     * @return array|int
     */
    public function bindAliAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (!isset($this->inputData['auth_code'])) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }

        $aliAPi = new Alipay();
        $accessToken = $aliAPi->token($this->inputData['auth_code']);
        if (FALSE === $accessToken) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $aliUserId = $aliAPi->info($accessToken);
        if (FALSE === $aliUserId) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        //插入支付宝userid 完成实名认证
        $sql = 'SELECT user_id FROM t_user WHERE ali_user_id = ?';
        $bindAliUserId = $this->db->getOne($sql, $aliUserId);
        if ($bindAliUserId && $bindAliUserId != $userId) {
            return new ApiReturn('', 317, '支付宝已被绑定');
        } else {
            $sql = 'UPDATE t_user SET ali_user_id = ? WHERE user_id = ? AND ali_user_id = 0';
            $this->db->exec($sql, $aliUserId, $userId);
        }
        return new ApiReturn();
    }

    /**
     * 获取用户渠道号
     * 优先级  热云>apk包默认
     * @return ApiReturn
     */
    public function sourceAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
//        sleep(5);
        $sql = 'SELECT IF(reyun_app_name = "", app_name, reyun_app_name) source, compaign_id FROM t_user WHERE user_id = ?';
        $userInfo = $this->db->getRow($sql, $userId);
//        return new ApiReturn(array('appSource' => ($userInfo ? $userInfo['source'] : '') . '_' . ($userInfo ? ($userInfo['compaign_id'] ?: '') : ''), 'compaignId' => $userInfo ? ($userInfo['compaign_id'] ?: '') : ''));
        return new ApiReturn(array('appSource' => $userInfo ? ($userInfo['source'] . '_' . ($userInfo['compaign_id'] ?: '')) : '', 'compaignId' => ($userInfo && $userInfo['compaign_id']) ? $userInfo['compaign_id'] : ''));
    }

    /**
     * 获取tab内容
     * @return ApiReturn
     */
    public function tabAction () {
        //scratch 刮刮卡 box 鱼玩游戏
        $sql = 'SELECT variable_value FROM t_variable WHERE variable_name = ?';
        $tabType = $this->db->getOne($sql, 'tab_type');
        return new ApiReturn(array('tab' => $tabType));
    }

    /**
     * 用户读取提现信息
     */
    public function readWithdrawAction () {
        $userId = $this->model->user2->verifyToken();
        if ($userId instanceof apiReturn) {
            return $userId;
        }
        if (isset($this->inputData['id']) && $this->inputData['id']) {
            $sql = 'UPDATE t_withdraw SET withdraw_user_read = 1 WHERE withdraw_id = ? AND user_id = ?';
            $this->db->exec($sql, $this->inputData['id'], $userId);
            return new ApiReturn();
        }
        return new ApiReturn('', 205, '访问失败，请稍后再试');
    }

    /**
     * 保存用户上传图片
     * @param $code base64
     * @return ApiReturn|string
     */
    protected function uploadImage ($code) {
//        $code = 'data:image/png;base64,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';
//        strlen($code);
        if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $code, $result)){
            $ext = strtolower($result[2]);
//            if (!in_array($ext, array('jpg','jpeg', 'png', 'gif', 'bmp'))) {
//                return new ApiReturn('', 313,'上传图片格式不正确');
//            }
            
            $length = strlen($code) - strlen($result[1]);
            $size = $length - $length / 4;
            if ($size > 1024 * 1024) {
                return new ApiReturn('', 316,'上传图片大小不能超过1M');
            }
            $saveFile = (ENV_PRODUCTION ? '' : 'test-') . substr(md5(substr($code, 20)), 10) . time() . '.' . strtolower($ext);

            file_put_contents('/tmp/' . $saveFile, base64_decode(str_replace($result[1], '', $code)));

            $oss = new Oss();
            $uploadReturn = $oss->upload('upload/' . $saveFile, '/tmp/' . $saveFile);
            if ($uploadReturn !== TRUE) {
                return new ApiReturn('', 314,'上传失败');
            }
            return 'upload/' . $saveFile;
            
//            $saveFile = date('Ymd') . '/';
//            if (!is_dir(UPLOAD_IMAGE_DIR . $saveFile)) {
//                $a = mkdir(UPLOAD_IMAGE_DIR . $saveFile, 0755, true);
//            }
//
//            if (file_put_contents(UPLOAD_IMAGE_DIR . $saveFile, base64_decode(str_replace($result[1], '', $code)))) {
//                return 'upload/image/' . $saveFile;
//            } else {
//                return new ApiReturn('', 314,'上传失败');
//            }
        }else{
            return new ApiReturn('', 314,'上传失败');
        }
    }


}