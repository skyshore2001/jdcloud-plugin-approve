// "审批记录",
(function () {

var empTable = "Employee";
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
		url: WUI.makeUrl(empTable+".query", {res:"id,name"})
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
			type: "t",
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

// 问题：empId可能关联Employee，也可能关联User，须根据showPage时的pageFilter.empTable参数（不传即使用"Employee"）确定字段的下拉选项。
// WUI.showPage("pageUi", {uimeta: "metaApproveRec", pageFilter: {empTable:"User", cond: {...} }});
// 后端query接口也会处理empTable参数
UiMeta.on("beforeshow", "dlgUi_inst_metaApproveRec", function (ev, formMode, opt) {
	// 通过beforeshow中的opt.jtbl找到关联的列表，进而找到页面所在页面，进而取页面pageFilter参数。
	var jdlg = $(ev.target);
	var jtbl = opt.objParam.jtbl;
	if (!jtbl || jtbl.size() == 0)
		return;
	var jpage = jtbl.closest(".wui-page");
	if (jpage.size() == 0)
		return;
	var pageFilter = WUI.getPageFilter(jpage);
	var empTable1 = pageFilter.empTable || "Employee";
	if (empTable1 != empTable) {
		empTable = empTable1;
		jdlg.gn("empId").setOption(EmployeeGrid());
		jdlg.gn("approveEmpId").setOption(EmployeeGrid({jd_vField: "approveEmpName"}));
	}
});

return meta;
})()
