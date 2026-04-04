import './stimulus_bootstrap.js';

import './styles/app.css';

//import * as bootstrap from 'bootstrap';
import * as tabler from '@tabler/core';
import '@tabler/core/dist/css/tabler.min.css';
import '@andypf/json-viewer';

window.bootstrap = tabler.bootstrap;

console.log(tabler);
