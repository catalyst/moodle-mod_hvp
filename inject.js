// This gets called before the DOM is loaded, so wait until it is
setTimeout(async () => {
    window.console.log("Inject script running");

    var elements = Array.from(document.getElementsByClassName('12345'));
    var fetchPromises = elements.map((e) => {
        return new Promise((resolve, reject) => {
            fetch(e.href)
            .then(res => res.text())
            .then(text => resolve(text));
        });
    });

    var results = await Promise.all(fetchPromises);

    window.console.log("INJECT got results");
    results.forEach(r => eval(r));
});

