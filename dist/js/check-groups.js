document.addEventListener('DOMContentLoaded', function() {
	const dataNode = document.getElementById('active-groups-ids');
	if (
		typeof dataNode !== undefined &&
		dataNode !== null &&
		typeof dataNode.dataset !== undefined &&
		dataNode.dataset !== null
	) {
		const groupsData = dataNode.dataset.groups;
		if (groupsData && typeof groupsData !== undefined && groupsData !== null) {
			ids = JSON.parse(groupsData);
			ids.map(id => {
				let checkBox = document.getElementById(
					`in-dt_ext_connection_group-${id}`
				);
				checkBox.checked = true;
				checkBox.onclick = () => false;
			});
		}
	}
});
