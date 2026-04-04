import { startStimulusApp } from '@symfony/stimulus-bundle';
import Timeago from '@stimulus-components/timeago'
import ReadMore from '@stimulus-components/read-more'
import JsonViewerController from './controllers/json_viewer_controller.js'

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('timeago', Timeago)
app.register('read-more', ReadMore)
app.register('json-viewer', JsonViewerController)
