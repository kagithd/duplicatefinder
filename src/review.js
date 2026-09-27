import { generateFilePath } from '@nextcloud/router'
import Vue from 'vue'
import Review from './Review.vue'

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath('duplicatefinder', '', 'js/')
Vue.mixin({ methods: { t, n } })
export default new Vue({ el: '#content', render: h => h(Review) })
