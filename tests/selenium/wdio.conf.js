exports.config = {
	specs: [
		'specs/*.js'
	],
	maxInstances: 1,
	capabilities: [
		{
			browserName: 'firefox'
		},
		{
			browserName: 'chrome'
		},
	],

	sync: true,
	logLevel: 'silent',
	coloredLogs: true,
	bail: 0,
	screenshotPath: '/tmp/',
	baseUrl: 'http://localhost',
	waitforTimeout: 10000,
	connectionRetryTimeout: 90000,
	connectionRetryCount: 3,
	framework: 'mocha',
	reporters: ['spec'],
	mochaOpts: {
		ui: 'bdd'
	}
}
