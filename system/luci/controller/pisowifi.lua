module("luci.controller.pisowifi", package.seeall)

function index()
    entry({"admin", "services", "pisowifi"}, template("pisowifi/admin"), "Pisowifi Manager", 60).dependent = false
end
