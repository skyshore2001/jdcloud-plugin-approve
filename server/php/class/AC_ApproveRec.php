<?php
class ApproveRec
{
/*
生成与审批记录表（ApproveRec）关联的一组虚拟字段，在AC类的onInit中调用：

	$this->vcolDefs[] = ApproveRec::vcols();
	// 对象的approveRecId字段关联ApproveRec，自动生成approveFlag, approveEmpId, approveDscr, approveStage, approveFlow这些虚拟字段；关联的审批记录表别名为ap。

或指定关联字段，按惯例，一个对象上定义了多个审批流程，则第2、第3个关联字段名为approveRecId2、approveRecId3依次类推，生成的虚拟字段则分别为approveFlag2, approveFlag3等。

	$this->vcolDefs[] = ApproveRec::vcols("approveRecId2");
	// 生成approveFlag2, approveEmpId2等虚拟字段，关联审批记录表别名为ap2。

*/
	static function vcols($field = "approveRecId", $vcolOpt = null) {
		$num = '';
		if (preg_match('/\d+$/', $field, $ms)) {
			$num = $ms[0];
		}
		$ret = [
			"res" => ["ifnull(ap{$num}.approveFlag, 0) approveFlag{$num}", "ap{$num}.approveEmpId approveEmpId{$num}", "ap{$num}.approveDscr approveDscr{$num}", "ap{$num}.approveStage approveStage{$num}", "ap{$num}.approveFlow approveFlow{$num}"], 
			"join" => "LEFT JOIN ApproveRec ap{$num} ON ap{$num}.id=t0.{$field}", 
			"default" => true
		];
		if (isArrayAssoc($vcolOpt))
			arrCopy($ret, $vcolOpt);
		return $ret;
	}

	// 返回true表示已匹配到审批流程并已执行
	static function autoRun($obj, $objId, $flowName=null) {
		assert($obj || $flowName);
		if (($v = param("doApprove")) != null) {
			if (! $v instanceof DbExpr)
				jdRet(E_SERVER, "doApprove should be DbExpr");
			if ($v->val == 0)
				return;
		}
		// 有审批配置，且满足条件则执行
		if ($flowName) {
			$conf= self::getConf($flowName);
			if (!$conf || $conf["disableFlag"])
				return;
			$confArr = [$conf];
		}
		else if ($obj) {
			$confArr= arrGrep(self::getConf(), function ($e) use ($obj) {
				return !$e["disableFlag"] && $obj == $e["obj"];
			});
			if (count($confArr) == 0)
				return;
		}

		$ret = null;
		foreach ($confArr as $conf) {
			// 自动发起审批仅发起一次，要再次发起须手工发起
			$approveFlag = queryOne("SELECT ap.approveFlag FROM {$conf['obj']} t0 LEFT JOIN ApproveRec ap ON ap.id=t0.{$conf['field']} WHERE t0.id=$objId");
			if ($approveFlag) {
				$ret = true;
				continue;
			}
			if (self::matchCond($objId, $conf)) {
				callSvcInt("ApproveRec.add", null, [
					"obj" => $conf["obj"],
					"objId" => $objId,
					"approveFlow"=>$conf["name"],
					"approveFlag" => 1
				]);
				$ret = true;
			}
		}
		return $ret;
	}

	// 指定name则返回该项审批流程（单项配置），否则返回审批流程配置数组
	static function getConf($name=null) {
		static $conf_approve;
		if (! isset($conf_approve)) {
			$val = queryOne("SELECT value FROM Cinf WHERE name='conf_approve'");
			if (!$val)
				return;
			$conf_approve = jsonDecode($val);
			if (!is_array($conf_approve))
				return false;
		}
		if (!$name)
			return $conf_approve;
		$ret = arrFind($conf_approve, function ($e) use ($name) {
			return $e["name"] == $name;
		});
		if (!$ret) {
			logit("找不到审批流程：$name");
		}
		return $ret;
	}

	static function matchCond($objId, $conf, $idx=0) {
		$stage = $conf["stages"][$idx];
		assert($stage);
		$cond = $stage["cond"];
		if (is_null($cond) || $cond == '' || $cond == '1')
			return true;
		if ($cond == '0' || startsWith($cond, '0 and '))
			return false;
		$vars = getVarsFromExpr($cond);
		if (!$vars)
			return evalExpr($cond, []);

		$obj = $conf["obj"];
		$rv = callSvcInt("$obj.get", ["id" => $objId, "res" => join(',', $vars)]);
		if (! $rv)
			return false;
		return evalExpr($cond, $rv);
	}

/*
isFirst=true表示发起审批时，应返回具有审批权限(根据role)人的id和name，否则返回空
若isFirst=false表示审批时，须判断当前用户$empId是否有审批权限，如果有权限，则返回该用户的id和name，否则返回空

$empId为当前操作人的编号，仅当!isFirst时有意义，isFirst时可能是发起人也可能是审批人（触发多级审批时）。
取发起人可使用`$originId = $getOriginId()`得到。

支持用户定义代码，示例：

	return ["id"=>1, "name"=>"张三"];

示例2：“区域管理员”角色，以User表作为用户，定义为：取与发起人(originId)相同客户组(cusId)且未绑定门店(storeId is null)的人

	$originId = $getOriginId();
	$cusId = callSvcInt("User.query", ["res"=>"cusId", "cond"=>["id"=>$originId], "fmt"=>"one?"]);
	if (!$cusId)
		return;
	$cond = ["cusId"=>$cusId, "storeId"=>"null"];
	if (! $isFirst) {
		$cond["id"] = $empId;
	}
	return callSvcInt("User.query", ["res"=>"id,name", "cond"=>$cond, "fmt"=>"one?"]);

*/
	static function getApprover($isFirst, $empId, $objId, $stage, $approveConf) {
		$role = $stage["role"];
		$code = $stage["getApprover"];
		if ($code) { // 自定义逻辑
			$getOriginId = function () use ($isFirst, $empId, $objId, $stage, $approveConf) {
				// 发起审批且是第1级时，操作人（empId）就是发起人
				if ($isFirst && $stage['role'] == $approveConf['stages'][0]['role'])
					return $empId;
				return ApproveRec::getOriginator($objId, $approveConf["name"]);
			};
			try {
				$rv = eval($code);
			}
			catch (Exception $ex) {
				logit("conf_approve getApprover eval fail: $ex, code=`$code`");
				jdRet(E_SERVER, null, "审批人设置出错: " . $ex->getMesaage());
			}
		}
		else {
			$cond = null;
			if ($isFirst) { // 发起审批
			}
			else {
				checkAuth(AUTH_EMP);
				$cond = ["id" => $empId];
			}
			$rv = callSvcInt("Employee.query", ["cond"=>$cond, "res"=>"id,name", "role"=>$role, "fmt"=>"one?"]);
			if ($rv) {
				$rv["name"] .= "(emp-{$rv['id']})";
			}
		}
		return $rv;
	}

	// 查看审批流程的发起人。注意查找逻辑须考虑可能已发起多次审批，要取最近一次的，如前4次审批的approveFlag为"1 3 1 1"(第2次值3是拒绝，第3次值1是重新发起，第4次通过值仍为1)，当第5次要通过，应取第3次的empId作为发起人
	static function getOriginator($objId, $approveFlow) {
		$ret = null;
		$rv = queryAll("SELECT empId,approveFlag FROM ApproveRec WHERE objId={$objId} AND approveFlow=" . Q($approveFlow) . " ORDER BY id DESC", true);
		foreach ($rv as $row) {
			if ($row["approveFlag"] == 1) {
				$ret = $row["empId"];
			}
			else {
				break;
			}
		}
		return $ret;
	}
}

class AC0_ApproveRec extends AccessControl
{
	protected $vcolDefs = [
		[
			"res" => ['emp.name empName'], 
			"join" => 'LEFT JOIN Employee emp ON emp.id=t0.empId', 
			"default" => true
		], 
		[
			"res" => ['approveEmp.name approveEmpName'], 
			"join" => 'LEFT JOIN Employee approveEmp ON approveEmp.id=t0.approveEmpId', 
			"default" => true
		]
	];

	protected $requiredFields = ['objId', 'approveFlag', 'approveFlow'];
	protected $allowedAc = ['add', 'query', 'setIf', 'delIf'];

	protected function onValidate() {
		$approveFlow = mparam("approveFlow", "P");
		$objId = mparam("objId", "P");
		$approveFlag = mparam("approveFlag", "P");

		$conf = ApproveRec::getConf($approveFlow);
		if (!$conf)
			jdRet(E_PARAM, "cannot find conf in conf_approve", "找不到匹配的审批流配置");

		/*
		$conf["stages"] = [
			["role"=>"部门经理", "cond"=> "amount>=1000" ],
			["role"=>"总经理", "cond"=> "amount>=10000" ]
		];
		*/
		checkAuth(AUTH_EMP|AUTH_USER);
		$empId = $_SESSION["empId"] ?: $_SESSION["uid"];
		assert($empId);
		$_POST["tm"] = date(FMT_DT);
		$_POST["empId"] = $empId;
		$_POST["obj"] = $conf["obj"];

		$getUserName = function () use ($empId) {
			if (hasPerm(AUTH_EMP)) {
				$empName = queryOne("SELECT name FROM Employee WHERE id=$empId") . "(emp-{$empId})";
			}
			else {
				$empName = queryOne("SELECT name FROM User WHERE id=$empId") . "(user-{$empId})";
			}
			return $empName;
		};

		// NOTE: empId是发起人或实际审批人，approveEmpId是指定审批人；他们默认是Employee对象，也可以自定义为User或其它对象
		if ($approveFlag == 1) { // 发起审批
			$stage = $conf["stages"][0];
			$_POST["approveStage"] = $stage["role"];
			$approver = ApproveRec::getApprover(true, $empId, $objId, $stage, $conf);
			if ($approver)
				$_POST["approveEmpId"] = $approver["id"];
			$empName = $getUserName();
			$dscr = "{$empName}发起审批,流程=$approveFlow";
		}
		else if ($approveFlag == 2 || $approveFlag == 3) {
			$doc = queryOne("SELECT approveFlag,approveStage,approveEmpId FROM ApproveRec WHERE id=(SELECT {$conf['field']} FROM {$conf['obj']} WHERE id={$_POST['objId']})", true);
			if ($doc["approveFlag"] == 0)
				jdRet(E_FORBIDDEN, "origin approveFlag=0", "尚未发起审批");
		
			// check role
			$role = $doc["approveStage"];
			$stages = $conf["stages"];
			$found = false;
			foreach ($stages as $stageIdx => $stage) {
				if ($role == $stage["role"]) {
					$found = true;
					break;
				}
			}
			if (!$found)
				jdRet(E_PARAM, "unknown stage", "当前审批阶段不正确: $role");

			$canApprove = ($empId == $doc["approveEmpId"] || hasPerm(PERM_MGR));
			if (!$canApprove) {
				$approver = ApproveRec::getApprover(false, $empId, $objId, $stage, $conf);
				if (!$approver || $approver["id"] != $empId)
					jdRet(E_FORBIDDEN, "approval requires role $role", "当前用户不允许审批，要求角色：$role");
			}
			else {
				$approver = ["id"=>$empId, "name"=>$getUserName()];
			}

			$dscr = null;
			$role = $doc["approveStage"];
			$_POST["approveStage"] = $role;
			$_POST["approveEmpId"] = $doc["approveEmpId"];
			if ($approveFlag == 2) { // accept
				// 进入下一审批阶段
				if ($stageIdx < count($stages)-1 && ApproveRec::matchCond($objId, $conf, $stageIdx+1)) {
					$_POST["approveStage"] = $stages[$stageIdx+1]["role"];
					// 发起下一阶段审批
					$approver = ApproveRec::getApprover(true, $empId, $objId, $stage, $conf);
					if ($approver)
						$_POST["approveEmpId"] = $approver["id"];
					$_POST["approveFlag"] = 1; // 改回发起审批状态
				}
			}
			else { // reject
			}

			$empName = $approver["name"];
			$dscr = ($approveFlag==2? "通过": "不通过") . ",审批人=$empName,角色=$role";
			if ($role != $_POST["approveStage"]) {
				$dscr .= ",下一角色=" . $_POST["approveStage"];
			}
		}
		else {
			jdRet(E_PARAM, "bad approveFlag=$approveFlag");
		}
		if (issetval("approveDscr")) {
			$_POST["approveDscr"] = $dscr . "," . $_POST["approveDscr"];
		}
		else {
			$_POST["approveDscr"] = $dscr;
		}

		$this->onAfterActions[] = function () use ($conf) {
			$arr = [];
			$arr[$conf["field"]] = $this->id; // approveRecId
			dbUpdate($conf["obj"], $arr, $_POST["objId"]);
			if ($conf["onOk"] && $_POST["approveFlag"] == 2) { // onOk回调
				$objId = $_POST["objId"];
				$approveRecId = $this->id;
				try {
					eval($conf["onOk"]);
				}
				catch (Exception $ex) {
					logit("conf_approve onOk eval fail: $ex, code=`" . $conf["onOk"] . "`");
					jdRet(E_SERVER, null, "审批成功后回调出错: " . $ex->getMesaage());
				}
			}
		};
	}
}

class AC1_ApproveRec extends AC0_ApproveRec
{
}

class AC2_ApproveRec extends AC0_ApproveRec
{
}

