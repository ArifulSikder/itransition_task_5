import { createApp } from 'vue';
import App from './App.vue';

const root = document.getElementById('app');
const config = JSON.parse(root.dataset.config);

createApp(App, { config }).mount(root);
