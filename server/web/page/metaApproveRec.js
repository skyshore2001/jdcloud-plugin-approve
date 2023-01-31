// "审批记录",
(function () {

function EmployeeGrid(opt) {
	return $.extend({
		jd_vField: "empName",
		panelWidth: 450,
		width: "95%",
		textField: "name",
		columns: [
			[
				{
					field: "id",
					title: "编号",
					width: 80
				},
				{
					field: "name",
					title: "名称",
					width: 120
				}
			]
		],
		url: WUI.makeUrl("Employee.query", {res:"id,name"})
	}, opt);
}

var meta = {
	obj: "ApproveRec",
	fields: [
		{
			name: "id",
			title: "编号",
			type: "i",
			uiType: "text",
		},
		{
			name: "tm",
			title: "创建时间",
			type: "tm",
			uiType: "text",
		},
		{
			name: "empId",
			title: "操作人",
			type: "i",
			linkTo: "Employee.name",
			uiType: "combogrid",
			opt: {
				combo: EmployeeGrid()
			},
		},
		{
			name: "approveFlag",
			title: "审批状态",
			type: "flag",
			uiType: "combo",
			opt: {
				enumMap: ApproveFlagMap,
				styler: Formatter.enumStyler(ApproveFlagStyler),
			}
		},
		{
			name: "approveEmpId",
			title: "审批人",
			type: "i",
			linkTo: "Employee.name",
			uiType: "combogrid",
			opt: {
				combo: EmployeeGrid({
					jd_vField: "approveEmpName"
				})
			},
		},
		{
			name: "approveDscr",
			title: "审批备注",
			type: "s",
			uiType: "text",
		},
		{
			name: "objId",
			type: "i",
			uiType: "text",
			title: "对象编号",
		},
		{
			name: "empName",
			title: "创建人",
			type: "s",
			notInList: true,
		},
		{
			name: "approveFlow",
			title: "审批流",
			type: "s",
			uiType: "text",
		},
		{
			name: "approveStage",
			title: "审批阶段",
			type: "s",
			uiType: "text",
		},
		{
			name: "approveEmpName",
			type: "s",
			notInList: true,
			title: "approveEmpName",
		},
		{
			name: "obj",
			title: "对象类型",
			type: "s",
			uiType: "text",
		}
	],
}
return meta;
})()
