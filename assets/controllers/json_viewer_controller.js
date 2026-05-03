import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['viewer', 'payload'];

    connect() {
        this.render();
    }

    render() {
        this.viewerTargets.forEach((viewer, idx) => {
            const payloadNode = this.payloadTargets[idx] ?? null;
            if (!payloadNode) {
                return;
            }

            try {
                const raw = (payloadNode.textContent ?? '').trim();
                viewer.data = raw === '' ? {} : JSON.parse(raw);
            } catch (error) {
                viewer.data = {
                    _error: 'Invalid JSON payload',
                };
            }
        });
    }
}
