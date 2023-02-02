# 审批管理

需求：

- 定义审批流程：当文档（即对象，下同）满足特定条件，可自动触发审批流程
- 审批支持多级设置，每级支持会签，或签等。支持按角色或按用户审批。
- TODO:审批提醒通知机制。通过对接asyncTask-通用通知服务中间件实现对接邮件，钉钉等。
- TODO:可对接外部BPM，如钉钉等。

支持特性如下：

- 一个文档（对象）可关联多个审批流。
- 审批流支持多级审批(每一级称为审批阶段/stage)，每级可指定触发条件和审批角色。条件是个类似SQL条件的表达式，可以使用对象的字段或虚拟字段。
- 审批流通过角色来找到审批人，也可以自定义审批人查询逻辑，其他人（指定审批人approveEmpId和最高管理员例外）无权操作状态转换。

TODO

- 转交
- 或批（N个人审批通过才算通过）每级可指定最少几人审批通过才能去下一级。
	比如要求财务部必须至少2人批准同意，这类需求太少，暂不支持，可在stage中增设minCnt字段来实现。
	- minCnt: 最少审批人数。为null时表示不检查（即至少1人）. 比如值为2，则表示该级审批须2位审批人同时同意才能通过。

## 用法

jdcloud-ganlan项目已默认支持，无须安装。
jdcloud项目先安装依赖插件，然后按以下步骤配置。

本插件依赖以下插件：

- jdcloud-plugin-jsonEditor
- jdcloud-plugin-conf (可选，最好有)

安装：

	./tool/jdcloud-plugin.sh add ../jdcloud-plugin-approve

由于引入了ApproveRec表，须在DESIGN.md中包含以便创建表：

	@include server\plugin\jdcloud-plugin-approve.README.md

### 审批配置示例

conf_approve为审批配置项，在系统配置中通过jsonEditor编辑。建议以二开方式直接在【系统设置】下添加一个【审批流配置】菜单项（详见后面开发手册）。

示例：采购单需要先经发起人所在部门主管审批，若金额超过10000元时，需要总经理审批。

【分析】

这是个多级审批的案例，每一级完成审批后到下一级审批，全部审批完成后，修改文档状态为审批通过。
同时，第一级审批，即部门审批，它要求只能由用户所在部门的主管来审批，而不是任何部门主管都可审批。
审批人可以通过角色来查找，再加以限制部门。

【解决方案】

员工上增加grp字段表示部门（用grp替代group是为了避开SQL关键字）。

假如采购单是Ordr对象（共用对象，且用字段type=POR来过滤）。

- 新增审批流: 名称=采购审批, 表名=Ordr, 关联字段名=approveRecId
- 新增两行审批阶段(stage)：
 - 角色=采购主管，条件=`type='POR'`，分组字段=grp，表示审批人须与发起人所在部门（即grp字段值）匹配
 - 角色=总经理, 条件=`amount>=10000`，表示金额超过10000时，须有“总经理”角色的员工来审批

员工表(Employee)除了默认支持的角色外，还应添加所在部门字段，比如Employee.grp字段（当然也可以是address, groupId等各种字段）。

如果找不到审批人，则工作流无法进行下去。软件将提示相关错误。

审批阶段如果不指定条件，则表示无条件触发，如果指定，则在条件匹配时触发。
条件是个类似SQL条件的表达式，可以使用对象的字段或虚拟字段。特别的，条件为`0`或`0 and `开头则不触发。

为了支持自动触发审批流程，需要添加后端逻辑，在onValidate中调用`ApproveRec::autoRun`。
管理端上可在页面工具栏上添加审批按钮，需要添加前端逻辑，数据表加载时添加`approveFlow`类型按钮。

## 数据库设计

审批过程:

@ApproveRec: id, tm, empId, obj, objId, approveFlag, approveEmpId, approveDscr(t), approveFlow, approveStage

- empId: 操作人，发起审批时为发起人，审批时为审批人。
- approveFlag: 审批状态（最终状态）

		0-无审批
		1-待审批
		2-审批通过
		3-审批不通过（或打回）

- approveEmpId: 指定的审批人，在发起审批时自动生成，TODO 或通过转交指定审批人。
- approveDscr: 审批备注
- approveFlow: 审批流名
- approveStage: 审批阶段名，多级审批时表示当前是哪一级。一般就是角色名。

审批设置直接复用Cinf表，配置项名为conf_approve（详细参考web/schema/conf_approve.js），以"conf_"开头的配置项可使用json编辑器编辑。结构：

	conf_approve: [{name/审批流程名, disableFlag, obj/表名, field/关联字段名, @stages, onOk()/审批通过逻辑}]

- field: 例如在Ordr中添加approveRecId字段关联ApproveRec表，则obj=Ordr, field=approveRecId
- stages: 审批阶段数组，[{role/审批角色即审批阶段名, cond/触发条件, getApprover/自定义审批人逻辑}]
	- role: 审批人角色
	- groupField: 找审批人时，除了匹配指定角色，还需要与发起人同组（即该字段值相同）
	- cond: 审批触发条件。空表示触发，0表示不触发。一个类似SQL查询条件的表达式，具体参考后端evalExpr函数(表达式计算引擎)，示例："amount>100 and status='PA'"，字段可以用对象的实际字段或虚拟字段。
	- getApprover(): 查询和验证审批人的自定义逻辑，如果不指定，则系统默认是用role作为员工角色来查询和验证身份。
- onOk(): 审批通过后的自定义逻辑。

【query接口参数】

- empTable: 区分员工审批还是用户审批，默认值为"Employee"。字段empId/approveEmpId默认关联Employee表，若要关联User或其它，需要指定该参数。
	示例：`callSvr("ApproveRec.query", {empTable: "User"})`

组织架构:
Require: @Employee: id, name, perms, group

支持审批的对象:
Require: @{Obj}: id, approveRecId, approveRecId2, ...

vcol
: approveFlag, approveEmpId, approveDscr, approveFlow, approveStage

这些虚拟字段须在后端AC类的`onInit`中用`ApproveRec::vcol()`进行添加，示例：

	$this->vcolDefs[] = ApproveRec::vcols();
	// 对象的approveRecId字段关联ApproveRec，自动生成approveFlag, approveEmpId, approveDscr, approveStage, approveFlow这些虚拟字段；关联的审批记录表别名为ap。

或指定关联字段，按惯例，一个对象上定义了多个审批流程，则第2、第3个关联字段名为approveRecId2、approveRecId3依次类推，生成的虚拟字段则分别为approveFlag2, approveFlag3等。

	$this->vcolDefs[] = ApproveRec::vcols("approveRecId2");
	// 生成approveFlag2, approveEmpId2等虚拟字段，关联审批记录表别名为ap2。

一般使用二次开发在列表而上添加approveFlag/approveDscr/approveEmpId字段，在对话框上显示approveFlag/approveDscr字段。

### getApprover / 自定义审批人逻辑

该逻辑用于查询和验证审批人，注意要考虑两种情况，返回`{id, name}`对象表示找到了审批人，在验证返回的id须与传入的$empId相同才允许审批。

- 变量$isFirst：值为true表示发起审批时，应返回具有审批权限(根据role)人的id和name，否则返回空
	若值为false表示审批时，须判断当前用户$empId是否有审批权限，如果有权限，则返回该用户的id和name，否则返回空

- $empId为当前操作人的编号，仅当!isFirst时有意义，isFirst时可能是发起人也可能是审批人（触发多级审批时）。
	取发起人可使用`$originId = $getOriginId()`得到。

示例1：

	return ["id"=>1, "name"=>"张三"];

示例2：“区域管理员”角色，以User表作为用户，定义为：取与发起人(originId)相同客户组(cusId)且未绑定门店(storeId is null)的人

	$originId = $getOriginId();
	$cusId = queryOne("SELECT cusId FROM User WHERE id=$originId");
	if (!$cusId)
		return;
	$cond = ["cusId"=>$cusId, "storeId"=>"null"];
	if (! $isFirst) {
		$cond["id"] = $empId;
	}
	return queryOne("SELECT id,name FROM User", true, $cond);

注意：如果是用户(User)身份发起调用，若用callSvcInt调用接口可能没有权限，可以用queryOne/queryAll来查询。

示例3：审批人为发起人的部门主管，若部门主管未设置，则再循环向上级部门找主管，如果最终找不到，则发起人即为审批人。
这类似于钉钉审批流中找审批人的逻辑。

数据库设计为，部门为树状结构，每个部门可设置主管，每个员工指定部门。

	@Employee: ..., deptId
	@Dept: id, fatherId, mgrId

实现逻辑供参考:

```php
// 验证总是失败，表示只能由指定审批人(approveEmpId)审批，不支持其它人审批。
if (! $isFirst)
	return;

$originId = $getOriginId();
$default = ["id"=>$originId, "name"=>"发起人(#$originId)"];

$deptId = queryOne("SELECT deptId FROM User WHERE id=$originId");
while (true) {
	if (!$deptId)
		return $default;
	$rv = queryOne("SELECT dp.fatherId deptId, mgr.id mgrId, mgr.name mgrName 
FROM Dept dp
LEFT JOIN User mgr ON mgr.id=dp.mgrId
WHERE dp.id=$deptId");
	if ($rv === false)
		return $default;
	$deptId = $rv["deptId"];
	if ($rv["mgrId"])
		return ["id"=>$rv["mgrId"], "name"=>$rv["mgrName"]];
}
```

## 交互接口

审批：

	ApproveRec.add()(approveFlow, objId, approveFlag)

- 权限：发起审批时员工或用户均可(AUTH_USER|AUTH_EMP)，审批或转交时，必须是指定审批人(操作人编号与approveEmpId一致)，或是最高管理员，或是与审批阶段中指定的角色相匹配。
	角色匹配：默认的角色匹配查找和验证员工的角色(Employee.perms)，要求员工（AUTH_EMP）权限，自定义审批人逻辑则可以自由定义。
- approveFlow: 审批流名，须与审批流配置（conf_approve）中的名字一致。
- objId: 关联对象编号。
- approveFlag: Enum(1-发起审批, 2-审批通过, 3-审批不通过/打回)
- 操作成功后，返回审批记录编号，会自动设置到对象的关联字段中（比如Ordr.approveRecId）。

发起审批:

	callSvr("ApproveRec.add", $.noop, {approveFlow, objId, approveFlag:1});

审批通过或不通过:

	callSvr("ApproveRec.add", $.noop, {approveFlow, objId, approveFlag:2或3});

审批记录只能查询、不能编辑和删除，但为了测试方便，可以批量编辑和删除。

## 测试用例/开发手册

开发概要：

- 审批流需要对象增加approveRecId字段，通过关联ApproveRec（审批记录）获取虚拟字段approveFlag/审批状态, approveEmpId/审批人, approveDesc/状态描述，approveFlow/审批流，approveStage/审批阶段。
- 如果需要自动触发，要在对象AC类的onValidate中调用ApproveRec::autoRun来触发。
- 前端在页面工具栏上添加审批按钮（approveFlow类型的按钮）。

测试功能点：

- 配置审批流程：审批阶段：1。单级审批，2。多级（多阶段）审批；审批阶段中设置自定义审批人逻辑；设置审批通过逻辑；同一对象支持多种不同审批，互相不影响
- 发起审批（1。自动，2。手工发起），自动找到审批人（1。直接按角色找Employee；2。按审批流中的自定义审批人逻辑找；3。找不到审批人）
- 审批拒绝，然后再通过（1。没有审批权限；2。匹配审批权限；3。无权限但是是审批人或最高管理员，审批人可以通过转交实现）
- 查看审批记录

### 用例1: 条件审批

审批流“订单毛利率”：当订单状态（由新创建CR）改为待服务（PA）时，如果订单金额大于100且毛利率小于0.3，则自动触发审批流程，
由“老板”来审批，此时状态先不改，在审批通过后订单状态自动改为待服务（PA）。

订单对象为Ordr；毛利率字段rate为二开新增字段。

#### 新增审批流程

操作：打开审批流配置

有两种方式，一是（菜单）系统设置-系统配置，（按钮）新增，填写：配置名=选择"conf_approve(审批流配置)"，【配置值】字段下出现【配置】按钮，点按钮即可配置。
二是定制菜单，以二开为例，打开【系统设置】-【开发】-【菜单管理】，添加一个二级菜单项【系统设置】-【审批流配置】，值=`JDConf("conf_approve")`。之后点该菜单项即可。

操作：新增审批流“订单毛利率”

- (菜单) 系统设置-审批流配置
- (按钮) 新增审批流，填写: 名称=订单毛利率，表名=Ordr，关联字段名=approveRecId
按惯例，关联字段用approveRecId，如果一个表上需要多个审批，则其它审批用approveRecId2, approveRecId3，各审批流程互不相关。

- (按钮) 新增审批阶段，填写：审批角色=老板，条件="amount>100 and rate<0.3"
- 勾上“审批通过逻辑”，编写代码：
	```php
	callSvcInt("Ordr.set", ["id"=>$objId, "doApprove"=>dbExpr(0)], ["status"=>"PA"]);
	```
	该代码表示在审批通过后修改订单状态，可以用`callSvcInt`也可以用`dbUpdate`来更新，前者调用接口会走后端更新逻辑，后者则直接修改数据库字段。
	URL参数doApprove设置为`dbExpr(0)`会自动略过自动发起审批的逻辑，避免更新时再次触发审批，这个参数设计为只接受dbExpr值，故只能在内部通过callSvcInt调用接口使用。

- (按钮)确定

结果：新增成功

#### 审批流程开发

以二次开发方式，为订单对象（Ordr）配置自动发起审批。

操作：先扩展Ordr对象，完成后端开发。

- 以开发模式打开管理端（url后加`?dev`参数）
- （菜单）系统设置-开发-数据模型
- （按钮）新增，填写：生成托管接口=扩展，对象名=Ordr，显示名=订单
- 字段边的（按钮）配置，打开【字段配置】对话框。由于是扩展对象，先清空列表的字段，然后
	- （按钮）新增字段，填写：名称=rate，类型=n-小数，显示名=毛利率
	- （按钮）新增字段，填写：名称=approveRecId，类型=i-整数，显示名=审批记录
	- （按钮）确定，关闭对话框。

- AC逻辑边的（按钮）配置，打开【AC逻辑】对话框，编写后端逻辑，勾上【onInit】，编写代码：
	```php
	// 新增一批虚拟字段，用于页面上显示
	$this->vcolDefs[] = ApproveRec::vcols();
	```

	勾上【onValidate】，编写代码：
	```php
	// 设置status时触发审批
	if ($this->ac == "set" && $_POST['status'] == 'PA') {
		// 此处指定了审批流程为"订单毛利率"，也可以不指定，则会检查所有Ordr上配置的审批流程。
		// 一旦发起成功（或流程已发起尚未结束），则清除status字段即不允许设置。status会在流程完成后自动设置。
		if (ApproveRec::autoRun("Ordr", $this->id, "订单毛利率")) {
			unset($_POST['status']);
		}
	}
	```

	（按钮）确定，关闭【AC逻辑】对话框。

- （按钮）确定，关闭【数据模型】对话框。
- 选中新加的行，点页面【数据模型】上的【同步】按钮，刷新数据库字段。

结果：Ordr对象支持审批相关字段。`Ordr.query`接口返回rate, approveRecId字段以及虚拟字段approveFlag, approveEmpId, approveDscr, approveFlow, approveStage.

操作：前端开发，在订单页面上显示审批相关字段。

- 【数据模型】页面中，双击点开Ordr对象的【数据模型】对话框，双击点开标签【页面】中的订单，打开【页面】对话框。
（也可以从菜单【开发】-【页面管理】中打开页面）

- 字段（按钮）配置，打开配置对话框。新增以下字段：
	- name/字段名=approveFlag, title/显示名=审批状态，uiType/UI类型=combo:下拉列表-固定值映射，opt/配置代码：
		```javascript
		{
			disabled: true,
			enumMap: ApproveFlagMap,
			styler: Formatter.enumStyler(ApproveFlagStyler),
			formatter: function (val, row) {
				return WUI.makeLink(val, function () {
					WUI.showPage("pageUi", {uimeta:"metaApproveRec", title: "审批记录-订单" + row.id, force: 1, pageFilter: {cond: {objId: row.id, approveFlow:row.approveFlow} }});
				})
			}
		}
		```
	- name=approveEmpId，title=审批人
	- name=approveDscr, title=审批备注, 类型="t-长文本"(将显示为多行文本), uiType="text-文本框", opt=`{disabled: true}`
	- name=approveStage, title=审批阶段, uiType="text-文本框", opt=`{disabled: true}`
	- name=approveFlow, title=审批流程, uiType="text-文本框", opt=`{disabled: true}`

	（按钮）确定，关闭配置对话框。

	这些字段是之前后端onInit中调用`ApproveRec::vcols()`后自动生成的，按需新增即可，一般只显示approveFlag, approveEmpId和approveDscr字段，
	由于都是关联字段，应设置disabled不可编辑；而approveRecId字段也常常设置为列表和对话框中都不显示。
- (按钮)确定，关闭【页面】对话框。

结果：打开订单页面及对话框，看到上述审批相关字段。

操作：在订单页面上新增审批工具栏按钮。

- （菜单）系统配置-开发-前端代码，编写代码
	```javascript
	UiMeta.on("dg_toolbar", "pageOrder", function (ev, buttons, jtbl, jdlg) {
		var btnApprove = ["approveFlow", {
			name: "订单毛利率",
			text: "毛利率审批",
		}];
		buttons.push(btnApprove);
	});
	```

结果：订单页面的工具栏上增加"毛利率审批"按钮。

#### 执行审批流

操作：新增角色“老板”

- 新增角色“老板”
- 新增员工user1，角色为管理员，新增员工user2，角色为管理员、老板。

操作：自动发起审批。

- 新增订单，填写：金额=200，毛利率=0.1。
此时：状态=新创建，审批状态=无审批。

- 将订单状态修改为“待服务”。

结果：

- 订单记录：状态=新创建（未变化！），审批状态=待审批，审批人=（user2的id）
- 点“审批状态”字段（即“待审批”链接），打开关联的审批记录页面，有一条记录: 操作人=admin，审批状态=待审批，审批人=user2，审批流程=订单毛利率，审批阶段=老板，对象类型=Ordr

操作：审批

- 新开隐身窗口，以user1用户登录，打开订单页面（注意此时用户无“老板”角色，无审批权限）
- 选中新增的待审批订单，（按钮）毛利率审批-审批不通过，弹出审批对话框中直接点击确定。
- 结果：报错，无权限审批。
- 在另一个管理端中（以最高管理员登录）为user1用户添加“老板”角色。
- 切回隐身窗口user1界面，再次审批该订单，（按钮）毛利率审批-审批不通过，点确定提交。
- 结果：订单记录：审批状态=不通过，审批备注=（自动填写），点【审批状态】打开【审批记录】页面，看到2条审批记录。
- 再次审批该订单，（按钮）毛利率审批-审批通过，在备注中任意填写如`abc`，点确定提交。

结果：
订单记录：审批状态=通过，状态=待服务（审批通过后自动更新逻辑），审批备注=（自动填写，包含刚刚填写的abc），点【审批状态】打开【审批记录】页面，看到3条审批记录。

操作：人工发起审批。
如果审批不通过，可以人工再次发起审批；或是在审批通过后，如果状态先回退后再改回来，无法再次自动触发审批流，需要人工发起审批。

- 审批状态=通过（或未通过）的订单，工具栏点（按钮）毛利率审批-发起审批，点（按钮）确定，提交审批。

结果：
- 订单：审批状态=待审批

操作：最高管理员有权审批，无论角色是否匹配（步骤略）

操作：不可修改或删除审批记录；但允许批量修改或批量删除，这非正常操作，仅应测试使用。

- 在订单行上，点【审批状态】打开【审批记录】页面
- 按住Ctrl点删除键，弹出批量删除确认框，点确定按钮删除该订单关联的审批记录。
- 回到订单列表，刷新订单行。

结果：订单行：审批状态=无审批，审批备注等字段均被清空。

TODO: 转交

### 用例2：多级审批

本用例与用例1共用Ordr对象，同时测试一个对象上多个审批、自定义审批人。

审批流“门店下单”，由门店用户（User）下单，下单后先由“区域管理员”审批，再由“总部管理员”审批。

其中“区域管理员”角色定义为：取与发起人(originId)相同客户组(grp)且职位为"区域管理员"(pos)的用户。用户上需要扩展grp和pos字段。
“总部管理员”直接设置为某人。

#### 用户对象扩展

操作：配置若干用户

- 以二次开发方式，为用户（User）扩展字段，新增字段：grp/客户组，pos/职位。
管理端中默认没有用户管理页面，可用超级管理端中拷贝过来：将web/adm/pageUser.html和pageUser.js拷贝到web/page下。

- 以二次开发方式配置菜单项，【系统设置】-【开发】-【菜单管理】中添加【运营管理】-【用户管理】菜单项，代码为`WUI.showPage("pageUser")`

- 添加4个用户，设置其客户组（grp）、职位（pos）：
	- user1: 登录名=user1, 姓名=用户1, 手机号=12345678901, 登录密码=1234, 客户组(grp)=销售1部 （将作为发起人）, 职位(pos)=（空）
	- user2: 登录名=user2, 姓名=用户2, 手机号=12345678902, 登录密码=1234, 客户组(grp)=销售1部, 职位(pos)=区域管理员
	- user3: 登录名=user3, 姓名=用户3, 手机号=12345678903, 登录密码=1234, 客户组(grp)=销售2部, 职位(pos)=区域管理员
	- user4: 登录名=user4, 姓名=用户4, 手机号=12345678904, 登录密码=1234, 客户组(grp)=（空）, 职位(pos)=（空）, 记下用户编号比如4, 下面将当作总部管理员用。

#### 新增审批流程

操作：新增审批流“门店下单”

- (菜单) 系统设置-审批流配置
- (按钮) 新增审批流，填写: 名称=门店下单，表名=Ordr，关联字段名=approveRecId2
- (按钮) 新增审批阶段，填写：审批角色=区域管理员，自定义审批人逻辑：
	```php
	$originId = $getOriginId();
	$grp = queryOne("SELECT grp FROM User WHERE id=$originId");
	if (!$grp)
		return;
	$cond = ["grp"=>$grp, "pos"=>"区域管理员"];
	if (! $isFirst) {
		$cond["id"] = $empId;
	}
	return queryOne("SELECT id,name FROM User", true, $cond);
	```
- (按钮) 新增审批阶段，填写：审批角色=总部管理员，自定义审批人逻辑中直接使用上面user4的信息：
	```php
	return ["id"=>4, "name"=>"总部管理员(user4)"];
	```

结果：新增成功

#### 审批流程开发

以二次开发方式，为订单对象（Ordr）配置审批。

操作：Ordr对象支持“门店下单”审批。

- 以开发模式打开管理端，数据模型中，扩展Ordr对象，新增字段：名称=approveRecId2，类型=i-整数，显示名=门店下单审批记录
	配置Ordr对象的【AC逻辑】，设置【onInit】代码：
	```php
	$this->vcolDefs[] = ApproveRec::vcols("approveRecId2"); 
	// 指定关联字段名，不指定时默认为approveRecId。虚拟字段分别为approveFlag2, approveEmpId2等。
	```

	设置【onValidate】代码：
	```php
	// 添加时触发审批
	if ($this->ac == "add") {
		$this->onAfterActions[] = function () {
			// 注意指定了审批流名称，要与此前配置中一致。
			ApproveRec::autoRun("Ordr", $this->id, "门店下单");
		};
	}
	```

- 页面中，打开订单页面，参考用例1，新增一些关联字段：
	- name/字段名=approveFlag2, title/显示名=审批状态2, uiType/UI类型="combo:下拉列表-固定值映射", opt/配置代码：
		```javascript
		{
			disabled: true,
			enumMap: ApproveFlagMap,
			styler: Formatter.enumStyler(ApproveFlagStyler),
			formatter: function (val, row) {
				return WUI.makeLink(val, function () {
					// 注意: 1。pageFilter过滤条件中要用row.approveFlow2字段；2。由于是关联用户(User)表，须指定`empTable:"User"`参数作为`ApproveRec.query`查询条件
					WUI.showPage("pageUi", {uimeta:"metaApproveRec", title: "门店下单审批记录-订单" + row.id, force: 1, pageFilter: {cond: {objId: row.id, approveFlow:row.approveFlow2}, empTable: "User" }});
				})
			}
		}
		```
	- name=approveEmpId2, title=审批人2
	- name=approveDscr2, title=审批备注2, 类型="t-长文本"(将显示为多行文本), uiType="text-文本框", opt=`{disabled: true}`
	- name=approveStage2, title=审批阶段2, uiType="text-文本框", opt=`{disabled: true}`
	- name=approveFlow2, title=审批流程2, uiType="text-文本框", opt=`{disabled: true}`

结果：打开订单页面及对话框，看到上述审批相关字段。

注意：与用例1不同，不必在订单页面上新增审批工具栏按钮，因为是在用户端审批，而不是在管理端审批。

#### 执行审批流

以下以最高管理员登录管理端，用户登录则使用浏览器隐身窗口打开用户端，互不干扰。

操作：审批

- 打开User登录的系统（m2/index.html），以user1身份(用户名user1/密码1234)登录进去并创建订单。
- 结果：在管理端中看到新订单：审批状态2=待审批，审批人2=(user2的编号)，审批阶段2=区域管理员，审批流程2=门店下单，点“待审批”链接打开审批明细，看到明细信息一致。
记下订单编号比如为999，下面做审批要用。

- user1界面内，按F12打开浏览器控制台，调用审批通过接口：`callSvr("ApproveRec.add", $.noop, {approveFlow:"门店下单", objId: 999, approveFlag:2})`
- 结果：报错，没有权限审批。

- 用户端user1退出登录，切换为user2登录系统，再次调用上述审批通过接口
- 结果：在管理端中查看订单：审批状态2=待审批(注意不是"通过",因为还有下一阶段)，审批人2=(user2编号)，审批流程2=门店下单，审批阶段2=总部管理员(进入第2阶段)
点链接查看审批记录列表，与上面信息一致。

- 再次调用上述审批通过接口
- 结果：报错，没有权限审批。

- 切换user4登录系统，再次调用上述审批接口
- 结果：在管理端中查看订单：审批状态2=通过，审批备注2=(检查是否正确)，

- 在管理端修改user1的客户组：grp=销售2部
- 回到用户端，以user1身份登录并再下一单
- 结果：在管理端中看到新订单：审批状态2=待审批，审批人2=(user3的编号)，记下订单编号，如1000。

- 以user3登录系统，审批不通过：`callSvr("ApproveRec.add", $.noop, {approveFlow:"门店下单", objId: 1000, approveFlag:3})`
- 结果：在管理端中查看订单：审批状态2=不通过，审批阶段2=区域管理员

- user3审批通过：`callSvr("ApproveRec.add", $.noop, {approveFlow:"门店下单", objId: 1000, approveFlag:2})`
- 结果：在管理端中查看订单：审批状态2=待审批(注意不是"通过"，因为还有下一阶段)，审批阶段2=总部管理员

- user3再次审批不通过：`callSvr("ApproveRec.add", $.noop, {approveFlow:"门店下单", objId: 1000, approveFlag:3})`
- 结果：报错，没有权限审批。(此时已是总部管理员审批阶段)

- 切换user4登录系统，审批不通过：`callSvr("ApproveRec.add", $.noop, {approveFlow:"门店下单", objId: 1000, approveFlag:3})`
- 结果：在管理端中查看订单：审批状态2=不通过，审批阶段2=总部管理员

- user4审批通过：`callSvr("ApproveRec.add", $.noop, {approveFlow:"门店下单", objId: 1000, approveFlag:2})`
- 结果：在管理端中查看订单：审批状态2=通过，审批阶段2=总部管理员

