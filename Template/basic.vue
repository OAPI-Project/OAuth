<template>
  <div class="plugin-oauth-basic">
    <v-row dense class="user-count-cards">
      <v-col cols="3" v-for="(item, key) in userCount" :key="key">
        <v-card :color="item.color" dark>
          <div class="count">{{ item.count }}</div>
          <div class="text-title">{{ item.text }}</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card v-if="loaded === true" class="basic-options">
      <div class="options-title">基本设置</div>
      <div class="options-content"></div>
    </v-card>

    <v-card v-if="loaded === true" class="bot-auth-key">
      <div class="bot-title">Bot AuthKey</div>
      <div class="bot-content">
        <div v-if="hasBotAuthKey === false">还未设置 Bot AuthKey</div>
        <v-textarea
          label="Bot AuthKey"
          :value="BotAuthKey"
          readonly
          @click="copyResult"
          :data-clipboard-text="BotAuthKey"
          v-if="hasBotAuthKey === true"
          ref="BotAuthKeyInput"
        ></v-textarea>

        <div class="bot-buttons">
          <v-btn
            class="submit-btn"
            color="light-blue darken-1"
            :disabled="BotAuthKeyButton.disabled"
            :loading="BotAuthKeyButton.loading"
            @click="getBotAuthKey"
          >
            {{ BotAuthKeyButton.text }}
          </v-btn>
        </div>
      </div>
    </v-card>

    <v-snackbar
      :value="snackBar.status"
      bottom
      right
      color="success"
      style="z-index: 1000"
    >
      {{ snackBar.text }}
    </v-snackbar>
  </div>
</template>

<script>
function generate() {
  return {
    data: () => ({
      userCount: [
        { count: "NaN", text: "总用户数", color: "light-blue darken-1" },
        { count: "NaN", text: "Friends", color: "teal darken-1" },
        { count: "NaN", text: "普通用户", color: "green darken-1" },
        { count: "NaN", text: "封禁用户", color: "red darken-1" },
      ],
      loaded: false,
      hasBotAuthKey: false,
      BotAuthKey: "",
      BotAuthKeyButton: {
        disabled: true,
        loading: true,
        text: "",
      },
      snackBar: {
        status: false,
        text: "",
      },
    }),

    mounted() {
      this.init();
      this.$store.commit("Global/changePageTitle", "OAuth Options");
      this.$store.commit("Global/changeSiteTitle", "OAuth Options");
    },

    methods: {
      init() {
        this.loaded = false;
        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=basic",
          "GET",
          undefined,
          (data) => {
            if (data.status === 200) {
              let _data = data.data.data;

              this.userCount[0].count = _data.count.all;
              this.userCount[1].count = _data.count.friend;
              this.userCount[2].count = _data.count.normal;
              this.userCount[3].count = _data.count.banned;

              this.hasBotAuthKey = _data.bot.set === true ? true : false;
              this.BotAuthKey = _data.bot.auth_key != null ? _data.bot.auth_key : "";
              this.BotAuthKeyButton.disabled = false;
              this.BotAuthKeyButton.loading = false;
              this.BotAuthKeyButton.text =
                this.hasBotAuthKey === true
                  ? "重新生成 Bot AuthKey"
                  : "初始化 Bot AuthKey";

              this.loaded = true;
            }
          },
          (error) => {},
          this.$tools.getAuthorization()
        );
      },

      getBotAuthKey() {
        if (this.loaded === false) return false;
        this.BotAuthKeyButton.disabled = true;
        this.BotAuthKeyButton.loading = true;

        this.$serve.axios(
          this.$serve.baseAPI + "oauth?method=set_bot_auth",
          "GET",
          undefined,
          (data) => {
            if (data.status === 200) {
              let _data = data.data.data;
              this.BotAuthKey = _data.bot_auth_key;

              this.BotAuthKeyButton.disabled = false;
              this.BotAuthKeyButton.loading = false;
              this.BotAuthKeyButton.text = "重新生成 Bot AuthKey";

              this.hasBotAuthKey = true;
            }
          },
          (error) => {},
          this.$tools.getAuthorization()
        );
      },

      copyResult() {
        if (this.$refs.BotAuthKeyInput == undefined || this.BotAuthKey == "")
          return false;

        var clipboard = new this.$clipboard(".bot-auth-key .bot-content textarea");
        document.querySelector(".bot-auth-key .bot-content textarea").select();
        var _this = this;
        clipboard.on("success", (e) => {
          document.querySelector(".bot-auth-key .bot-content textarea").select();
          _this.snackBar.status = true;
          _this.snackBar.text = "已成功复制 (*/ω＼*)";
          setTimeout(() => {
            _this.snackBar.status = false;
          }, 3000);
          clipboard.destroy();
        });
      },
    },
  };
}
</script>

<style scoped>
.user-count-cards .v-card {
  padding: 15px;
  text-align: center;
  user-select: none;
  border-radius: 8px;
}

.user-count-cards .v-card .count {
  font-family: "kule-english";
  font-size: 36px;
}

.user-count-cards .v-card .text-title {
  font-size: 23px;
}

.bot-auth-key,
.basic-options {
  margin-top: 15px;
  margin-bottom: 15px;
  border-radius: 8px !important;
}

.bot-auth-key .bot-title,
.basic-options .options-title {
  font-size: 23px;
  padding: 15px;
  user-select: none;
  border-bottom: 1px solid #e0e0e0;
}

.bot-auth-key .bot-content,
.basic-options .options-content {
  padding: 15px;
}

.bot-content .bot-buttons {
  margin-top: 10px;
  display: flex;
  justify-content: flex-end;
}

.bot-content .bot-buttons button {
  color: #fff;
}

@media (max-width: 900px) {
  .user-count-cards .col {
    flex: 0 0 100% !important;
    max-width: 100% !important;
  }
}
</style>
