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

- 角色限制同组内
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

### 多级审批且带条件

示例：采购单需要先经发起人所在部门主管审批，若金额超过10000元时，需要总经理审批。

【分析】

这是个多级审批的案例，每一级完成审批后到下一级审批，全部审批完成后，修改文档状态为审批通过。

同时，第一级审批，即部门审批，它要求只能由用户所在部门的主管来审批，而不是任何部门主管都可审批。

审批人可以通过角色来查找。为了限制部门，还需要引入部门概念，必须部门匹配了，才能再匹配角色。

为了支持自动触发审批流程，需要开发在onValidate的合适条件下调用`ApproveRec::autoRun($obj, $objId, $approveFlow)`

【解决方案】

假如采购单是Ordr对象（共用对象，且用字段type=POR来过滤）。

- 新增审批流: 名称=采购审批, 表名=Ordr, 关联字段名=approveRecId
- 新增两行审批阶段(stage)：
 - 角色="经理"，条件=`type='POR'`，TODO: 限定=group-同组 (表示审批人须与发起人所在部门即group的值匹配)
 - 角色=总经理, 条件=`amount>=10000`，表示金额超过10000时，须有“总经理”角色的员工来审批

TODO: 员工表除设置角色(Employee.perms字段)外，还应设置所在部门即须有Employee.group字段（可定义虚拟字段）。(TODO: 如何支持多级组织，用自定义审批人逻辑？)

TODO: 如果找不到审批人，则工作流无法进行下去。软件应提示相关错误。

审批阶段如果不指定条件，则表示无条件触发，如果指定，则在条件匹配时触发。
条件是个类似SQL条件的表达式，可以使用对象的字段或虚拟字段。特别的，条件为`0`或`0 and `开头则不触发。

## 数据库设计

审批过程:

@ApproveRec: id, tm, empId, obj, objId, approveFlag, approveEmpId, approveDscr, approveFlow, approveStage

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
	- role: 审批人角色，TODO: 支持限定部门。
	- cond: 审批触发条件。空表示触发，0表示不触发。一个类似SQL查询条件的表达式，具体参考后端evalExpr函数(表达式计算引擎)，示例："amount>100 and status='PA'"，字段可以用对象的实际字段或虚拟字段。
	- getApprover(): 查询和验证审批人的自定义逻辑，如果不指定，则系统默认是用role作为员工角色来查询和验证身份。
- onOk(): 审批通过后的自定义逻辑。

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

示例：

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
	- name=approveEmpId，title=审批人, opt:
		```javascript
		{
			disabled: true,
		}
		```
	- name=approveDscr, title=审批备注, opt:
		```javascript
		{
			disabled: true,
			format: "textarea"
		}
		```
	- name=approveStage, title=审批阶段, opt=`{disabled: true}`
	- name=approveFlow, title=审批流程, opt=`{disabled: true}`

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

本用例基于用例1，同时测试一个对象上多个审批、自定义审批人。

审批流“门店下单”，由门店用户（User）下单，下单后先由区域管理员审批，再由总部管理员审批，审批通过后，自动将订单状态改为已审批。

#### 新增审批流程

操作：新增审批流“门店下单”

- (菜单) 系统设置-审批流配置
- (按钮) 新增审批流，填写: 名称=门店下单，表名=Ordr，关联字段名=approveRecId2
- (按钮) 新增审批阶段，填写：审批角色=区域管理员，自定义审批人逻辑：
	```php
	TODO
	```
- (按钮) 新增审批阶段，填写：审批角色=总部管理员。
- (按钮)确定

结果：新增成功

#### 审批流程开发

以二次开发方式，为订单对象（Ordr）配置审批。

操作：Ordr对象支持“门店下单”审批。

- 以开发模式打开管理端（url后加`?dev`参数）
- 数据模型中，为Ordr对象新增字段：名称=approveRecId2，类型=i-整数，显示名=门店下单审批记录
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
			ApproveRec::autoRun("Ordr", $this->id, "门店下单");
		};
	}
	```
- 页面中，打开订单页面，参考用例1，新增一些关联字段：
	- name/字段名=approveFlag2, title/显示名=审批状态2，uiType/UI类型=combo:下拉列表-固定值映射，opt/配置代码：
		```javascript
		{
			disabled: true,
			enumMap: ApproveFlagMap,
			styler: Formatter.enumStyler(ApproveFlagStyler),
			formatter: function (val, row) {
				return WUI.makeLink(val, function () {
					// 注意后面要用row.approveFlow2
					WUI.showPage("pageUi", {uimeta:"metaApproveRec", title: "门店下单审批记录-订单" + row.id, force: 1, pageFilter: {cond: {objId: row.id, approveFlow:row.approveFlow2} }});
				})
			}
		}
		```
	- name=approveEmpId2，title=审批人2, opt:
		```javascript
		{
			disabled: true,
		}
		```
	- name=approveDscr2, title=审批备注2, opt:
		```javascript
		{
			disabled: true,
			format: "textarea"
		}
		```
	- name=approveStage2, title=审批阶段2, opt=`{disabled: true}`
	- name=approveFlow2, title=审批流程2, opt=`{disabled: true}`

结果：打开订单页面及对话框，看到上述审批相关字段。

操作：在订单页面上新增审批工具栏按钮。

- （菜单）系统配置-开发-前端代码，编写代码（与用例1相应代码合在一起）
	```javascript
	UiMeta.on("dg_toolbar", "pageOrder", function (ev, buttons, jtbl, jdlg) {
		var btnApprove = ["approveFlow", {
			name: "订单毛利率",
			text: "毛利率审批",
		}];
		var btnApprove2 = ["approveFlow", {
			name: "门店下单",
			text: "门店下单审批",
			approveFlagField: "approveFlag2"
		}];
		buttons.push(btnApprove, btnApprove2);
	});
	```

结果：订单页面的工具栏上增加"毛利率审批"和"门店下单审批"按钮。

#### 执行审批流(TODO)

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

