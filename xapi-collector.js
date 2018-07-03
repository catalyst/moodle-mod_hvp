/*global H5P*/
/**
 * Collect results from xAPI events
 */
(function ($){

    /**
     * Finds a H5P library instance in an array based on the content ID
     *
     * @param  {Array} instances
     * @param  {number} contentId
     * @returns {Object|null} Content instance
     */
    function findInstanceInArray(instances, contentId){
        if (instances !== undefined && contentId !== undefined) {
            for (var i = 0; i < instances.length; i++) {
                if (instances[i].contentId === contentId) {
                    return instances[i];
                }
            }
        }
    }

    /**
     * Finds the global instance from content id by looking through the DOM
     *
     * @param {number} [contentId] Content identifier
     * @returns {Object} Content instance
     */
    function getH5PInstance(contentId){
        var iframes;
        var instance = null; // Returning null means no instance is found.

        // No content id given, search for instance.
        if (!contentId) {
            instance = H5P.instances[0];
            if (!instance) {
                iframes = document.getElementsByClassName('h5p-iframe');
                // Assume first iframe.
                instance = iframes[0].contentWindow.H5P.instances[0];
            }
        }
        else {
            // Try this documents instances.
            instance = findInstanceInArray(H5P.instances, contentId);
            if (!instance) {
                // Locate iframes.
                iframes = document.getElementsByClassName('h5p-iframe');
                for (var i = 0; i < iframes.length; i++) {
                    // Search through each iframe for content.
                    instance = findInstanceInArray(iframes[i].contentWindow.H5P.instances, contentId);
                    if (instance) {
                        break;
                    }
                }
            }
        }

        return instance;
    }

    /**
     * Get xAPI data for content type and send off.
     *
     * @param {number} contentId Content id
     * @param {Object} event Original xAPI event
     */
    function storeXAPIData(contentId, event){
        var xAPIData;
        var instance = getH5PInstance(contentId);

        // Use getXAPIData contract, needed to get children.
        if (instance && instance.getXAPIData) {
            xAPIData = instance.getXAPIData();
        }
        else {
            // Fallback to event data.
            xAPIData = {
                statement: event.data.statement
            };
        }

        // Ship the xAPI result.
        $.post(H5PIntegration.ajax.xAPIResult, {
            contentId: contentId,
            xAPIResult: JSON.stringify(xAPIData)
        }).done(function (data){
            if (data.error) {
                console.debug('Storing xAPI results failed with error message:', data);
            }
        });
    }

    $(document).ready(function (){
        // No external dispatcher.
        if (!(window.H5P && H5P.externalDispatcher)) {
            console.debug('External dispatcher not found');
            return;
        }

        // No ajax path.
        if (!(window.H5PIntegration && H5PIntegration.ajax && H5PIntegration.ajax.xAPIResult)) {
            console.debug('No ajax path found');
            return;
        }

        // Get emitted xAPI data.
        H5P.externalDispatcher.on('xAPI', function (event){
            // Skip malformed events.
            var hasStatement = event && event.data && event.data.statement;
            if (!hasStatement) {
                return;
            }

            var statement = event.data.statement;
            var validVerb = statement.verb && statement.verb.display && statement.verb.display['en-US'];
            if (!validVerb) {
                return;
            }

            var isCompleted = statement.verb.display['en-US'] === 'answered' ||
                statement.verb.display['en-US'] === 'completed';
            var isChild = statement.context && statement.context.contextActivities &&
                statement.context.contextActivities.parent &&
                statement.context.contextActivities.parent[0] &&
                statement.context.contextActivities.parent[0].id;

            // Store only completed root events.
            if (isCompleted && !isChild) {
                // Get xAPI data with children if possible.
                storeXAPIData(this.contentId, event);
            }
        });
    });
})(H5P.jQuery);
