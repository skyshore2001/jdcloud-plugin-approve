// 系统配置对话框中添加下拉项：
CinfList.push({ name: "conf_approve", text: "conf_approve(审批流配置)", dscr: "审批流" });
// 可添加菜单项【审批流配置】，代码为：JDConf("conf_appove");

/**
@key toolbar-approveFlow 审批流审批菜单

参数选项：

- name: 审批流名称，须与审批流配置(conf_approve)中的名称一致。

其它参数参考toolbar-approve（除ac/obj, onSet参数外），常用列举如下：

- text: 指定按钮显示文字，默认为"审批"
- approveFlagField: 默认值为"approveFlag"，审批状态字段名。

示例：列表页工具栏上显示审批菜单(以二次开发为例)

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

做二次开发时，对话框上approveFlag设置示例：

	{
		disabled: true,
		enumMap: ApproveFlagMap,
		styler: Formatter.enumStyler(ApproveFlagStyler),
		formatter: function (val, row) {
			return WUI.makeLink(val, function () {
				WUI.showPage("pageUi", {uimeta:"metaApproveRec", title: "毛利率审批-订单" + row.id, force: 1, pageFilter: {cond: {objId: row.id, approveFlow:row.approveFlow} }});
				// 如果是approveFlag2，则注意后面要用row.approveFlow2
				// WUI.showPage("pageUi", {uimeta:"metaApproveRec", title: "门店下单审批-订单" + row.id, force: 1, pageFilter: {cond: {objId: row.id, approveFlow:row.approveFlow2} }});
			})
		}
	}
*/
$.extend(WUI.dg_toolbar, {
	approveFlow: function (ctx, opt) {
		WUI.assert(opt.name, "approveFlow: 选项name未指定");
		opt = $.extend({
			ac: "ApproveRec.add",
			onSet: function (row, data, title) {
				data.objId = row.id;
				data.approveFlow = opt.name;
				if (opt.approveFlagField && opt.approveFlagField != "approveFlag") {
					data.approveFlag = data[opt.approveFlagField];
					delete data[opt.approveFlagField];
				}
				var dfd = $.Deferred();
				var meta = [
					// title, dom, hint?
					{title: "审批备注", dom: "<textarea name='approveDscr' rows=5></textarea>", hint: '选填'},
				];
				var jdlg = WUI.showDlgByMeta(meta, {
					title: title,
					onOk: async function (data1) {
						data.approveDscr = data1.approveDscr;
						dfd.resolve();
						WUI.closeDlg(jdlg);
					}
				});
				return dfd;
			}
		}, opt);
		return WUI.dg_toolbar.approve.call(this, ctx, opt);
	}
});
