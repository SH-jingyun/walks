<?php

//累计邀请多次好友的用户发放奖励
//  一小时执行一次
require_once __DIR__ . '/../init.inc.php';

$db = Db::getDbInstance();

$model = new Model();
$wechatPay = new Wxpay();
while (true) {
    $sql = 'SELECT * FROM t_withdraw WHERE withdraw_status = "pending" AND withdraw_amount = 0.3 AND withdraw_method = "wechat" ORDER BY withdraw_id';
    $withdrawList = $db->getAll($sql);

    foreach ($withdrawList as $withdrawInfo) {
        $sql = 'SELECT COUNT(withdraw_id) FROM t_withdraw WHERE user_id = ? AND withdraw_amount IN (0.3, 1) AND withdraw_status = "success"';
        if ($db->getOne($sql, $withdrawInfo['user_id'])) { //to do failure reason from api return
            $model->withdraw->updateStatus(array('withdraw_status' => 'failure', 'withdraw_remark' => '新用户专享', 'withdraw_id' => $withdrawInfo['withdraw_id']));
        } elseif ($withdrawInfo['can_withdraw']) {
            if (strtotime($withdrawInfo['create_time']) > (time() - 60)) {
                continue;
            }
            $returnStatus = $wechatPay->transfer($withdrawInfo['withdraw_amount'], $withdrawInfo['wechat_openid']);
            if (TRUE === $returnStatus) {
                $model->gold->updateGold(array('user_id' => $withdrawInfo['user_id'], 'gold' => $withdrawInfo['withdraw_gold'], 'source' => "withdraw", 'type' => "out", 'relation_id' => $withdrawInfo['withdraw_id']));
                $model->withdraw->updateStatus(array('withdraw_status' => 'success', 'withdraw_id' => $withdrawInfo['withdraw_id']));
            } else {
                //to do failure reason from api return
                $model->withdraw->updateStatus(array('withdraw_status' => 'failure', 'withdraw_remark' => $returnStatus, 'withdraw_id' => $withdrawInfo['withdraw_id']));
            }
        } elseif (strtotime($withdrawInfo['create_time']) < (time() - 3 * 3600)) {
            $videoCount = $model->gold->withdrawVideoCount($withdrawInfo['user_id']);
            if ($videoCount >= 2) {
                $returnStatus = $wechatPay->transfer($withdrawInfo['withdraw_amount'], $withdrawInfo['wechat_openid']);
                if (TRUE === $returnStatus) {
                    $model->gold->updateGold(array('user_id' => $withdrawInfo['user_id'], 'gold' => $withdrawInfo['withdraw_gold'], 'source' => "withdraw", 'type' => "out", 'relation_id' => $withdrawInfo['withdraw_id']));
                    $model->withdraw->updateStatus(array('withdraw_status' => 'success', 'withdraw_id' => $withdrawInfo['withdraw_id']));
                } else {
                    $model->withdraw->updateStatus(array('withdraw_status' => 'failure', 'withdraw_remark' => $returnStatus, 'withdraw_id' => $withdrawInfo['withdraw_id']));
                }
            } else {
                $model->withdraw->updateStatus(array('withdraw_status' => 'failure', 'withdraw_remark' => '观看视频数量不足两个', 'withdraw_id' => $withdrawInfo['withdraw_id']));
            }
        }
    }

    sleep(3);
}
echo 'done';