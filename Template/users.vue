<template>
  <div class="plugin-oauth-users">
    <div class="users-list-add-box">
      <v-card>
        <div class="user-list-title">添加用户</div>
        <div class="user-list-content">
          <div class="add-user-input">
            <v-select
              :items="adduserBox.selectItem"
              label="权限组"
              v-model="adduserBox.group"
            ></v-select>

            <v-textarea
              label="备注"
              rows="1"
              auto-grow
              counter
              v-model="adduserBox.remark"
              hint="可选项"
            ></v-textarea>
          </div>
          <div class="add-user-buttons">
            <v-btn
              color="light-blue darken-1"
              :disabled="adduserBox.btn.disabled"
              :loading="adduserBox.btn.loading"
              @click.stop="addUser()"
            >
              添加用户
            </v-btn>
          </div>
        </div>
      </v-card>
    </div>

    <div class="users-list-data-box">
      <v-card v-if="usersTable.hasData === true">
        <v-data-table
          disable-pagination
          :hide-default-footer="true"
          :headers="usersTable.headers"
          :items="usersTable.items"
          :loading="usersTable.loading"
          :loading-text="usersTable.loadText"
        >
          <template v-slot:item.apikey="{ item }">
            <span
              v-if="item.displayAllAPIKey != undefined && item.displayAllAPIKey === true"
            >
              {{ item.apikey }}
            </span>
            <span v-else>{{ displaySomeAPIKey(item.apikey) }}</span>
            <span class="display-or-hidden-click" @click.stop="displayOrHidden(item)">{{
              item.displayAllAPIKey != undefined && item.displayAllAPIKey === true
                ? "隐藏"
                : "显示"
            }}</span>
          </template>
          <template v-slot:item.actions="{ item }">
            <v-tooltip top>
              <template v-slot:activator="{ on, attrs }">
                <v-btn icon v-bind="attrs" v-on="on" @click.stop="editDialog(item)">
                  <v-icon>mdi-pencil</v-icon>
                </v-btn>
              </template>
              <span>编辑 UID{{ item.userid }} 的配置</span>
            </v-tooltip>

            <v-tooltip top>
              <template v-slot:activator="{ on, attrs }">
                <v-btn
                  icon
                  color="red lighten-1"
                  v-bind="attrs"
                  v-on="on"
                  @click.stop="deleteUser(item)"
                >
                  <v-icon>mdi-delete</v-icon>
                </v-btn>
              </template>
              <span>删除 UID{{ item.userid }} 的全部设置</span>
            </v-tooltip>
          </template>
        </v-data-table>
      </v-card>

      <v-card v-if="usersTable.hasData === false" class="table-none-data">
        没有任何用户 (￣﹃￣)
      </v-card>
    </div>

    <v-dialog persistent max-width="600" :value="deldialog.status">
      <v-card>
        <v-card-title class="text-h5">
          {{ deldialog.title }}
        </v-card-title>
        <v-card-text>
          {{ deldialog.text }}
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn
            color="blue darken-1"
            text
            :disabled="deldialog.cancelBtn.disabled"
            @click="
              deldialog.status = false;
              deluser_tempdata = {};
              nowdeluser = null;
            "
            >取消</v-btn
          >
          <v-btn
            color="red lighten-1"
            text
            @click="deleteUser(nowdeluser)"
            :disabled="deldialog.submitBtn.disabled"
            :loading="deldialog.submitBtn.loading"
          >
            确认删除
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog width="600" persistent max-width="600" :value="optionsDialog.status">
      <v-card>
        <v-card-title class="text-h5">
          {{ optionsDialog.title }}
        </v-card-title>
        <v-card-text>
          <div class="api-key-input-box">
            <v-textarea
              label="APIKey"
              rows="1"
              auto-grow
              readonly
              :value="optionsDialog.submitData.apikey"
              hint="刷新 APIKey 自动保存，无需点击保存修改"
              persistent-hint
            ></v-textarea>

            <v-tooltip top>
              <template v-slot:activator="{ on, attrs }">
                <v-btn
                  color="red lighten-1"
                  v-bind="attrs"
                  v-on="on"
                  text
                  icon
                  class="api-key-refresh-btn"
                  @click.stop="updateAPIKey()"
                  :disabled="optionsDialog.refreshBtn.disabled"
                  :loading="optionsDialog.refreshBtn.loading"
                >
                  <v-icon>mdi-refresh</v-icon>
                </v-btn>
              </template>
              <span>重置 APIKey</span>
            </v-tooltip>
          </div>

          <v-select
            :items="optionsDialog.selectItem"
            label="权限组"
            v-model="optionsDialog.submitData.group"
          ></v-select>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn
            color="red lighten-1"
            text
            :disabled="optionsDialog.cancelBtn.disabled"
            @click="
              optionsDialog.status = false;
              optionsDialog.changeUser = {};
            "
            >取消保存</v-btn
          >
          <v-btn
            color="blue darken-1"
            text
            :disabled="optionsDialog.submitBtn.disabled"
            :loading="optionsDialog.submitBtn.loading"
            @click.stop="updateUserGroup()"
          >
            保存修改
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar
      v-model="snackBar.status"
      bottom
      right
      :timeout="3000"
      :color="snackBar.color"
      style="z-index: 1000"
    >
      {{ snackBar.text }}
    </v-snackbar>
  </div>
  <!--
// 别问我为什么全部东西挤在一起不模块化
// 远程加载 vue 单文件模板是真的麻烦
// 能跑起来就很好了（别问 问就是我菜（为了实现这个高血压了都
  -->
</template>

<script>
function generate() {
  return {
    data: () => ({
      usersTable: {
        hasData: true,
        loading: true,
        loadText: "正在获取用户列表中 _(:з)∠)_...",
        headers: [
          { text: "UID", align: "start", value: "userid" },
          { text: "APIKey", sortable: false, align: "start", value: "apikey" },
          { text: "权限组", align: "start", value: "group" },
          { text: "注册时间", align: "start", value: "created" },
          { text: "操作", sortable: false, align: "start", value: "actions" },
        ],
        items: [],
      },
      groups: {
        admin: "管理员",
        friend: "朋友",
        normal: "普通用户",
        banned: "被封禁用户",
      },
      adduserBox: {
        btn: {
          disabled: true,
          loading: false,
        },
        group: "",
        remark: "",
        selectItem: [
          {
            text: "管理员",
            value: "admin",
          },
          {
            text: "朋友",
            value: "friend",
          },
          {
            text: "普通用户",
            value: "normal",
          },
          {
            text: "封禁用户",
            value: "banned",
          },
        ],
      },
      deluser_tempdata: {},
      nowdeluser: null,
      deldialog: {
        status: false,
        title: "",
        text: "",
        submitBtn: {
          loading: false,
          disabled: false,
        },
        cancelBtn: {
          disabled: false,
        },
      },
      snackBar: {
        status: false,
        text: "",
        color: "",
      },
      optionsDialog: {
        status: false,
        changeUser: {},
        submitData: {
          id: "",
          apikey: "",
          group: "",
        },
        submitBtn: {
          loading: false,
          disabled: false,
        },
        cancelBtn: {
          disabled: false,
        },
        refreshBtn: {
          loading: false,
          disabled: false,
        },
        selectItem: [
          {
            text: "管理员",
            value: "admin",
          },
          {
            text: "朋友",
            value: "friend",
          },
          {
            text: "普通用户",
            value: "normal",
          },
          {
            text: "封禁用户",
            value: "banned",
          },
        ],
      },
    }),

    watch: {
      "usersTable.items"() {
        this.usersTable.hasData = this.usersTable.items.length > 0 ? true : false;
      },
    },

    mounted() {
      this.$store.commit("Global/changePageTitle", "OAuth Users");
      this.$store.commit("Global/changeSiteTitle", "OAuth Users");
      setTimeout(() => this.initTable(), 1000);
    },

    methods: {
      initTable() {
        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=list",
          "GET",
          undefined,
          (data) => {
            if (data.status === 200) {
              let _data = data.data.data;
              if (_data.length > 0) {
                _data.forEach((item, key) => {
                  this.usersTable.items.push({
                    userid: item.id,
                    apikey: item.apikey,
                    group: this.groups[item.group],
                    created: this.$dayjs(item.created * 1000).format(
                      "YYYY-MM-DD HH:mm:ss"
                    ),
                    raw: item,
                  });
                });
              } else {
                this.usersTable.hasData = false;
              }
              this.usersTable.loading = false;
              this.adduserBox.btn.disabled = false;
            }
          },
          (error) => {
            this.adduserBox.btn.disabled = false;
          },
          this.$tools.getAuthorization()
        );
      },

      addUser() {
        if (this.adduserBox.group == "") {
          this.snackBar.text = "请选择你要添加的用户的权限组 つ﹏⊂";
          this.snackBar.color = "warning";
          this.snackBar.status = true;
          return false;
        }

        this.adduserBox.btn.disabled = true;
        this.adduserBox.btn.loading = true;

        var _config = {
          add_source: {
            source: "web",
            isAdmin: true,
            adminID: this.$cookie.get("admin_uid"),
          },
          remark: this.adduserBox.remark != "" ? this.adduserBox.remark : "",
        };

        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=add",
          "POST",
          {
            group: this.adduserBox.group,
            use_config: true,
            config: JSON.stringify(_config),
          },
          (data) => {
            if (data.status === 200) {
              if (data.data != undefined) {
                var _data = data.data.data;

                this.usersTable.items.splice(0, 0, {
                  userid: _data.id,
                  apikey: _data.apikey,
                  group: this.groups[_data.group],
                  created: this.$dayjs(_data.created * 1000).format(
                    "YYYY-MM-DD HH:mm:ss"
                  ),
                  raw: _data,
                });

                this.adduserBox.group = "";
                this.adduserBox.remark = "";
                this.adduserBox.btn.disabled = false;
                this.adduserBox.btn.loading = false;

                this.snackBar.text = "已成功添加用户 (UID" + _data.id + ")";
                this.snackBar.color = "success";
                this.snackBar.status = true;
              }
            }
          },
          (error) => {},
          this.$tools.getAuthorization()
        );
      },

      deleteUser(item) {
        if (
          this.deluser_tempdata[item.userid] == undefined ||
          this.deluser_tempdata[item.userid] != true
        ) {
          this.nowdeluser = item;
          this.deluser_tempdata[item.userid] = true;
          this.deldialog.title = "操作确认";
          this.deldialog.text = "确认要删除 UID" + item.userid + " 的所有用户数据吗？";
          this.deldialog.status = true;
          return false;
        }

        this.deldialog.submitBtn.loading = true;
        this.deldialog.submitBtn.disabled = true;
        this.deldialog.cancelBtn.disabled = true;

        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=delete",
          "DELETE",
          {
            uid: item.userid,
            apikey: item.apikey,
          },
          (data) => {
            if (data.status === 200) {
              for (var i = 0; i < this.usersTable.items.length; i++) {
                if (this.usersTable.items[i].userid == item.userid) {
                  this.usersTable.items.splice(i, 1);
                  break;
                }
              }
              this.snackBar.text = "已成功将 UID" + item.userid + " 给删除了 ψ(｀∇´)ψ";
              this.snackBar.color = "success";
              this.snackBar.status = true;

              this.deldialog.submitBtn.loading = false;
              this.deldialog.submitBtn.disabled = false;
              this.deldialog.cancelBtn.disabled = false;
              this.deldialog.status = false;
              this.nowdeluser = null;
              this.deluser_tempdata = {};

              var _this = this;
              setTimeout(() => {
                _this.deldialog.title = "";
                _this.deldialog.text = "";
              }, 300);
            }
          },
          (error) => {},
          this.$tools.getAuthorization()
        );
      },

      displaySomeAPIKey(apikey) {
        return (
          apikey.slice(0, 4) + "***" + apikey.substring(apikey.length - 4, apikey.length)
        );
      },

      displayOrHidden(item) {
        let _data =
          item.displayAllAPIKey != undefined && item.displayAllAPIKey === true
            ? false
            : true;

        var _suid = 0;
        for (var i = 0; i < this.usersTable.items.length; i++) {
          if (this.usersTable.items[i].userid == item.userid) {
            _suid = i;
            break;
          }
        }
        this.$set(this.usersTable.items[_suid], "displayAllAPIKey", _data);
      },

      editDialog(item) {
        this.optionsDialog.changeUser = item;
        this.optionsDialog.title = "修改 UID" + item.userid + " 的信息";
        this.optionsDialog.submitData.id = item.raw.id;
        this.optionsDialog.submitData.apikey = item.raw.apikey;
        this.optionsDialog.submitData.group = item.raw.group;
        this.optionsDialog.status = true;
      },

      updateUserGroup() {
        if (
          this.optionsDialog.changeUser == undefined ||
          this.optionsDialog.changeUser == {}
        ) {
          return false;
        }

        let _userdata = this.optionsDialog.changeUser;

        if (_userdata.raw.group == this.optionsDialog.submitData.group) {
          return false;
        }

        this.optionsDialog.submitBtn.disabled = true;
        this.optionsDialog.submitBtn.loading = true;
        this.optionsDialog.cancelBtn.disabled = true;
        this.optionsDialog.refreshBtn.disabled = true;

        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=modify_user_settings",
          "POST",
          {
            do: "update_group",
            uid: _userdata.userid,
            new_group: this.optionsDialog.submitData.group,
          },
          (data) => {
            if (data.status === 200) {
              if (data.data != undefined) {
                var _data = data.data.data;
                this.optionsDialog.submitData.group = _data.group;
                this.optionsDialog.changeUser.group = this.groups[_data.group];

                var _suid = 0;
                for (var i = 0; i < this.usersTable.items.length; i++) {
                  if (this.usersTable.items[i].userid == _userdata.userid) {
                    _suid = i;
                    break;
                  }
                }
                this.usersTable.items[_suid].raw.group = _data.group;
                this.$set(
                  this.usersTable.items[_suid],
                  "group",
                  this.groups[_data.group]
                );

                this.optionsDialog.submitBtn.disabled = false;
                this.optionsDialog.submitBtn.loading = false;
                this.optionsDialog.cancelBtn.disabled = false;
                this.optionsDialog.refreshBtn.disabled = false;
                this.optionsDialog.status = false;

                this.snackBar.text = "已成功更新 UID" + _data.id + " 的用户组 _(:з)∠)_";
                this.snackBar.color = "success";
                this.snackBar.status = true;
              }
            }
          },
          (error) => {},
          this.$tools.getAuthorization()
        );
      },

      updateAPIKey() {
        if (
          this.optionsDialog.changeUser == undefined ||
          this.optionsDialog.changeUser == {}
        ) {
          return false;
        }

        let _userdata = this.optionsDialog.changeUser;
        this.optionsDialog.submitBtn.disabled = true;
        this.optionsDialog.cancelBtn.disabled = true;
        this.optionsDialog.refreshBtn.disabled = true;
        this.optionsDialog.refreshBtn.loading = true;

        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=modify_user_settings",
          "POST",
          {
            do: "refresh_apikey",
            uid: _userdata.userid,
          },
          (data) => {
            if (data.status === 200) {
              if (data.data != undefined) {
                var _data = data.data.data;
                this.optionsDialog.submitData.apikey = _data.apikey;
                this.optionsDialog.changeUser.apikey = _data.apikey;

                var _suid = 0;
                for (var i = 0; i < this.usersTable.items.length; i++) {
                  if (this.usersTable.items[i].userid == _userdata.userid) {
                    _suid = i;
                    break;
                  }
                }
                this.usersTable.items[_suid].raw.apikey = _data.apikey;
                this.$set(this.usersTable.items[_suid], "apikey", _data.apikey);

                this.optionsDialog.submitBtn.disabled = false;
                this.optionsDialog.cancelBtn.disabled = false;
                this.optionsDialog.refreshBtn.disabled = false;
                this.optionsDialog.refreshBtn.loading = false;
              }
            }
          },
          (error) => {},
          this.$tools.getAuthorization()
        );
      },
    },
  };
}
</script>

<style noscoped>
.plugin-oauth-users {
  margin-bottom: 20px;
}

.users-list-add-box .v-card {
  border-radius: 8px;
  margin-bottom: 15px;
}

.user-list-title {
  font-size: 23px;
  padding: 15px;
  user-select: none;
  border-bottom: 1px solid #e0e0e0;
}

.user-list-content {
  padding: 15px;
}

.users-list-data-box .v-card {
  border-radius: 8px;
  overflow: hidden;
}

.add-user-buttons {
  margin-top: 15px;
  display: flex;
  justify-content: flex-end;
}

.add-user-buttons button {
  color: #fff !important;
}

.display-or-hidden-click::before {
  content: "(";
}

.display-or-hidden-click::after {
  content: ")";
}

.display-or-hidden-click {
  margin-left: 3px;
  font-size: 12px;
  cursor: pointer;
  user-select: none;
  -webkit-user-select: none;
  opacity: 0.55;
}

.table-none-data {
  height: 180px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 23px;
  color: #1e88e5 !important;
}

.api-key-input-box {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
}

.api-key-refresh-btn {
  margin-left: 5px;
}
</style>
