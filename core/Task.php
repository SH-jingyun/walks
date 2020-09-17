<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

Class Task extends AbstractController {
    
    public function getTask ($type, $userId, $versionCode = 0) {
        $sql = 'SELECT * FROM t_activity WHERE activity_type = ?';
        $activityInfo = $this->db->getRow($sql, $type);
        if (!$activityInfo) {
            return new ApiReturn('', 205, '访问失败，请稍后再试');
        }
        $today = date('Y-m-d');
        switch ($type) {
            case 'walk':
            case 'walk_stage':
                $walkReward = new WalkCounter2($userId);
                $taskInfo = $walkReward->getReturnInfo($type);
                break;
            case 'newer':
            case 'lottery':
            case 'lottery_count':
            case 'phone':
            case 'do_invite':
            case 'invited_count':
            case 'drink':
                return new ApiReturn('', 205, '访问失败，请稍后再试');
            case 'wechat':
                $unionId = $this->model->user->userInfo($userId, 'unionid');
                $taskInfo = array('isBuild' => $unionId ? 1 : 0, 'award' => $activityInfo['activity_award_min']);
                break;
            case 'invited':
                $sql = 'SELECT COUNT(*) FROM t_user_invited WHERE invited_id = ?';
                $isInvited = $this->db->getOne($sql, $userId);
                $taskInfo = array('isBuild' => $isInvited ? 1 : 0, 'award' => $activityInfo['activity_award_min']);
                break;
            case 'sign':
                $sql = 'SELECT check_in_days FROM t_user WHERE user_id = ?';
                $checkInDays = $this->db->getOne($sql, $userId);
                $sql = 'SELECT receive_id id , receive_gold num, receive_status isReceive, is_double isDouble FROM t_gold2receive WHERE user_id = ? AND receive_date = ? AND receive_type = ?';
                $todayInfo = $this->db->getRow($sql, $userId, $today, $type);
                if(!$todayInfo) {
                    $isSignLastDay = $this->model->gold->existSourceDate($userId, date('Y-m-d', strtotime("-1 day")), $type);
                    if (!$isSignLastDay) {
                        $checkInDays = 0;
                        $sql = 'UPDATE t_user SET check_in_days = ? WHERE user_id = ?';
                        $this->db->exec($sql, 0, $userId);
                    }
                    if ($versionCode >= 230) {
                        $receiveType = 'sign_230';
                    } else {
                        $receiveType = 'sign';
                    }
                    //获取奖励金币范围
                    $sql = 'SELECT award_min FROM t_award_config WHERE config_type = :type AND counter_min = :counter';
                    $awardRow = $this->db->getRow($sql, array('type' => $receiveType, 'counter' => (($checkInDays + 1) % 7) ?? 7));

                    $goldId = $this->model->goldReceive->insert(array('user_id' => $userId, 'gold' => $awardRow['award_min'], 'type' => $type));
                    $todayInfo = array('id' => $goldId, 'num' => $awardRow['award_min'], 'isReceive' => 0, 'isDouble' => 0);
                }
                $fromDate = $today;
                $checkInReturn = array('checkInDays' => $checkInDays, 'checkInInfo' => array());
                if ($checkInDays) {
                    $checkInDays -= ($todayInfo['isReceive'] ?? 0);
                    $fromDate = date('Y-m-d', strtotime('-' . $checkInDays . 'days'));
                }
                $checkInInfo = $this->model->gold->singGoldList($userId, $fromDate, $today, $type);
                $checkInInfo[] = array_merge(array('isToday' => 1), $todayInfo);
                
                $i = 0;
                $sql = 'SELECT counter_min, award_min FROM t_award_config WHERE config_type = ? ORDER BY config_id ASC';
                $checkInConfigList = $this->db->getAll($sql, $type);
                foreach ($checkInConfigList as $config) {
                    $checkInReturn['checkInInfo'][] = array_merge(array('day' => $config['counter_min'], 'award' => $config['award_min']), $checkInInfo[$i] ?? array());
                    $i++;
                }
                $taskInfo = $checkInReturn;
                break;
            default :
                $sql = 'SELECT COUNT(*) FROM t_gold2receive WHERE user_id = ? AND receive_date = ? AND receive_type = ?';
                $todayCount = $this->db->getOne($sql, $userId, $today, $type);
                if (!$todayCount) {
                    //第一次领取
                    $sql = 'SELECT * FROM t_gold2receive WHERE user_id = ? AND receive_date = ? AND receive_type = ? ORDER BY receive_id DESC LIMIT 1';
                    $historyLastdayInfo = $this->db->getRow($sql, $userId, date('Y-m-d', strtotime("-1 day")), $type);
                    if ($historyLastdayInfo && strtotime($historyLastdayInfo['end_time']) > time()) {
                        $endTime = $historyLastdayInfo['end_time'];
                    } else {
                        $endTime = date('Y-m-d H:i:s');
                    }

                    $sql = 'SELECT IFNULL(MAX(withdraw_amount), 0) FROM t_withdraw WHERE user_id = ? AND withdraw_status = "success"';
                    $withDraw = $this->db->getOne($sql, $userId);
                    
                    $sql = 'SELECT COUNT(*) FROM t_award_config_update WHERE config_type = ?  AND withdraw = ?';
                    $updateConfig = $this->db->getOne($sql, $type, $withDraw);
                    
                    if ($updateConfig && $withDraw) {
                        $sql = 'SELECT * FROM t_award_config_update WHERE config_type = ? AND (counter = 0 OR counter = ?) AND withdraw <= ? ORDER BY withdraw DESC';
                        $configInfo = $this->db->getRow($sql, $type, 1, $withDraw);
                        $gold = rand($configInfo['award_min'], $configInfo['award_max']);
                    } else {
                        $sql = 'SELECT * FROM t_award_config WHERE config_type = ? AND counter_min = ?';
                        $configInfo = $this->db->getRow($sql, $type, 1);
                        if ($configInfo) {
                            $gold = rand($configInfo['award_min'], $configInfo['award_max']);
                        } else {
                            $gold = rand($activityInfo['activity_award_min'], $activityInfo['activity_award_max']);
                        }
                    }
                    $this->model->goldReceive->insert(array('user_id' => $userId, 'gold' => $gold, 'type' => $type, 'end_time' => date('Y-m-d H:i:s')));
                }
                $sql = 'SELECT * FROM t_gold2receive WHERE user_id = ? AND receive_date = ? AND receive_type = ? ORDER BY receive_id DESC LIMIT 1';
                $historyInfo = $this->db->getRow($sql, $userId, $today, $type);

                $sql = 'SELECT COUNT(*) FROM t_gold2receive WHERE user_id = ? AND receive_date = ? AND receive_type = ? AND receive_status = 1';
                $receiveCount = $this->db->getOne($sql, $userId, $today, $type);
                $taskInfo = array('receiveCount' => $receiveCount, 
                    'endTime' => strtotime($historyInfo['end_time']) * 1000,
                    'isReceive' => $historyInfo['receive_status'],
                    'id' => $historyInfo['receive_id'],
                    'num' => $historyInfo['receive_gold'],
                    'serverTime' => time() * 1000,
                    'countMax' => $activityInfo['activity_max']);
                if ('tab' == $type) {
                    $taskInfo['probability'] = $activityInfo['activity_remark'];
                }
        }
        return $taskInfo;
    }
    
    public function doTask ($type, $userId) {
        
    }
    
    
}