var schema = {
	title: "审批流配置",
	type: "array",
	format: "tabs-top",
	items: {
		title: "审批流",
		type: "object",
		headerTemplate: "{{i1}} {{self.name}}({{self.obj}})",
		properties: {
			name: {
				title: "名称",
				type: "string",
				required: true,
			},
			disableFlag: {
				title: "禁用",
				type: "boolean",
				default: true
			},
			obj: {
				title: "表名",
				type: "string",
				required: true,
			},
			field: {
				title: "关联字段名",
				type: "string",
				required: true,
				default: "approveRecId"
			},
			stages: {
				title: "审批阶段",
				type: "array",
				format: "tabs",
				required: true,
				items: {
					title: "审批阶段",
					type: "object",
					headerTemplate: "{{i1}} {{self.role}}",
					properties: {
						role: {
							title: "审批角色",
							type: "string",
							required: true,
							description: "除了指定角色外，指定的人员或最高管理员也有权审批"
						},
						groupField: {
							title: "分组字段",
							type: "string",
							default: "grp",
							description: "如果填写，则审批人与发起人的该字段值相同，即在同一组。"
						},
						cond: {
							title: "条件",
							type: "string",
							description: "不填表示无条件执行；填0表示不执行；其它示例: amount>1000 and type='POR'"
						},
						getApprover: {
							title: "自定义审批人逻辑/getApprover",
							$ref: "#/definitions/phpCode",
							description: `参数<code>($isFirst/是否发起审批, $empId/isFirst时为审批人, $objId/文档编号, $getOriginId()/取发起人)</code><br>
成功应返回<code>{id/审批人编号,name/审批人名}</code><br>
如果不填，则根据审批角色和分组字段找审批人`,
							default: `return ["id"=>$empId, "name"=>"张三"];`
						}
					}
				}
			},
			onOk: {
				title: "审批通过逻辑: onOk($objId, $approveRecId)",
				$ref: "#/definitions/phpCode",
				default: 'callSvcInt("Ordr.set", ["id"=>$objId, "doApprove"=>dbExpr(0)], ["status"=>"SC"]);'
			}
		},
	},
	definitions: {
		phpCode: {
			type: "string",
			format: "php",
			options: {
				dependencies: {
					type: "default"
				},
				ace: {
					mode: {path: "ace/mode/php", inline: true},
					minLines: 5
				}
			},
		}
	}
};

({
	schema: schema,
	no_additional_properties: true,
	onValidate: function (data) {
		var map1 = {}, map2 = {};
		var ret = null;
		data.forEach(function (e) {
			if (map1[e.name]) {
				app_alert("审批流程名称不允许重复: " + e.name, "e");
				ret = false;
				return;
			}
			map1[e.name] = true;

			var field = e.obj + '.' + e.field;
			if (map2[field]) {
				app_alert("审批字段名不允许重复: " + field, "e");
				ret = false;
				return;
			}
			map2[field] = true;
		});
		return ret;
	}
})
