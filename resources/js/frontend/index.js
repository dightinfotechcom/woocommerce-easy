/** @format */

import { sprintf, __ } from "@wordpress/i18n";
import { registerPaymentMethod } from "@woocommerce/blocks-registry";
import { decodeEntities } from "@wordpress/html-entities";
import { getSetting } from "@woocommerce/settings";
import { useState, useEffect } from "@wordpress/element";
import axios from "axios";
const settings = getSetting("dummy_data", {});

const defaultLabel = __("Easymerchant", "woo-gutenberg-products-block");

const label = decodeEntities(settings.title) || defaultLabel;

const apiBaseUrl = "https://stage-api.stage-easymerchant.io";
const apiKey = "d024a5f6f189be781ebd30d10";
const secretKey = "d38a4eb39d46e3cf32f3d3217";

const PaymentFields = () => {
	const [cards, setCards] = useState([]);
	const [useSavedCard, setUseSavedCard] = useState(false);
	const [showNewCardForm, setShowNewCardForm] = useState(true);
	const [userId, setUserId] = useState(null);
	const [customerId, setCustomerId] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [cardDetails, setCardDetails] = useState({
		cardNumber: "",
		cardExpiry: "",
		cardCVC: "",
		cardholderName: "",
	});
	const [errors, setErrors] = useState({
		cardNumber: "",
		cardExpiry: "",
		cardCVC: "",
	});

	useEffect(() => {
		// Fetch user data to get the user ID and customer ID
		const fetchUserData = async () => {
			try {
				const response = await axios.get(`/wooeasy/wp-json/wp/v2/users`);
				if (response.data && response.data.length > 0) {
					const user = response.data[0];
					setUserId(user.id);
					setCustomerId(user.woocommerce_meta._customer_id);
				} else {
					setError("No user data found");
				}
			} catch (error) {
				setError("Error fetching user data");
				console.error("Error fetching user data:", error);
			} finally {
				setLoading(false);
			}
		};
		fetchUserData();
	}, []);

	useEffect(() => {
		// if (customerId) {
		console.log(customerId);
		// axios
		// 	.get(`${apiBaseUrl}/api/v1/card`, {
		// 		params: { customer: customerId },
		// 		headers: {
		// 			"X-Api-Key": apiKey,
		// 			"X-Api-Secret": secretKey,
		// 		},
		// 	})
		// 	.then((response) => {
		// 		if (response.data && response.data.Cards) {
		// 			setCards(response.data.Cards);
		// 			console.log("Cards Response:", response.data.Cards);
		// 		}
		// 	})
		// 	.catch((error) => {
		// 		console.error("Error fetching cards:", error);
		// 	});
		// }
	}, [customerId]);

	const handleCardChange = (event) => {
		setUseSavedCard(event.target.value === "exist");
		setShowNewCardForm(event.target.value === "new");
	};

	const handleInputChange = (e) => {
		const { name, value } = e.target;
		let formattedValue = value;
		let error = "";

		switch (name) {
			case "easymerchant-card-number":
				formattedValue = value.replace(/\D/g, "").slice(0, 16);
				formattedValue = formattedValue.replace(/(.{4})/g, "$1 ").trim();
				if (/\D/.test(value)) {
					error = "Card number must be numeric.";
				} else if (value.replace(/\D/g, "").length > 16) {
					error = "Card number cannot exceed 16 digits.";
				}
				break;

			case "easymerchant-card-expiry":
				formattedValue = value.replace(/\D/g, "");
				if (formattedValue.length > 2) {
					formattedValue = `${formattedValue.slice(
						0,
						2
					)} / ${formattedValue.slice(2, 6)}`;
				}
				if (
					!/^(0[1-9]|1[0-2])\/\d{4}$/.test(formattedValue) &&
					formattedValue.length === 7
				) {
					error = "Invalid date format.";
				} else {
					const [month, year] = formattedValue.split(" / ");
					const now = new Date();
					const inputDate = new Date(year, month - 1);
					if (inputDate < now) {
						error = "Expiry date cannot be in the past.";
					}
				}
				break;

			case "easymerchant-card-cvc":
				formattedValue = value.replace(/\D/g, "").slice(0, 3);
				if (/\D/.test(value)) {
					error = "CVC must be numeric.";
				} else if (value.length > 3) {
					error = "CVC cannot exceed 3 digits.";
				}
				break;

			case "easymerchant-card-holder-name":
				formattedValue = value;
				break;

			default:
				break;
		}

		setCardDetails({ ...cardDetails, [name]: formattedValue });
		setErrors({ ...errors, [name]: error });
	};

	if (loading) {
		return <p>{__("Loading", "woocommerce-easymerchant")}</p>;
	}
	if (error) {
		return <p>{error}</p>;
	}

	// let data = JSON.stringify({
	// 	payment_mode: "auth_and_capture",
	// 	card_number: "4242424242424242",
	// 	exp_month: "01",
	// 	exp_year: "2025",
	// 	cvc: "123",
	// 	currency: "usd",
	// 	cardholder_name: "test",
	// 	name: "Jim",
	// 	email: "jim@easymerchant.io",
	// 	amount: "10.00",
	// 	description: "test",
	// });

	// let config = {
	// 	method: "post",
	// 	maxBodyLength: Infinity,
	// 	url: "https://stage-api.stage-easymerchant.io/api/v1/charges/",
	// 	headers: {
	// 		"X-Api-Key": "d024a5f6f189be781ebd30d10",
	// 		"X-Api-Secret": "d38a4eb39d46e3cf32f3d3217",
	// 		"Content-Type": "application/json",
	// 	},
	// 	data: data,
	// };

	// axios
	// 	.request(config)
	// 	.then((response) => {
	// 		console.log(JSON.stringify(response.data));
	// 	})
	// 	.catch((error) => {
	// 		console.log(error);
	// 	});
	return (
		<div className="img-payment-fields">
			<>
				<input
					type="radio"
					id="exist_card"
					name="emwc_card"
					value="exist"
					onChange={handleCardChange}
				/>
				<label htmlFor="exist_card">Use saved cards</label>
				<input
					type="radio"
					id="new_card"
					name="emwc_card"
					value="new"
					onChange={handleCardChange}
					defaultChecked
				/>
				<label htmlFor="new_card">Use a new payment method</label>
			</>

			<div
				style={{ display: useSavedCard ? "none" : "block" }}
				id="img-payment-data">
				<fieldset>
					<p className="form-row form-row-wide">
						<label htmlFor="card-number">
							Card Number <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="easymerchant-card-number"
							name="easymerchant-card-number"
							className="input-text wc-credit-card-form-card-number"
							inputmode="numeric"
							placeholder="•••• •••• •••• ••••"
							autocomplete="cc-number"
							value={cardDetails["easymerchant-card-number"]}
							onChange={handleInputChange}
							required
						/>
						{errors["easymerchant-card-number"] && (
							<span className="error">
								{errors["easymerchant-card-number"]}
							</span>
						)}
					</p>
					<p className="form-row form-row-first">
						<label htmlFor="card-expiry">
							Expiry Date <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="easymerchant-card-expiry"
							name="easymerchant-card-expiry"
							placeholder="MM / YY"
							className="input-text wc-credit-card-form-card-expiry"
							inputmode="numeric"
							autocomplete="cc-exp"
							value={cardDetails["easymerchant-card-expiry"]}
							onChange={handleInputChange}
							required
						/>
						{errors["easymerchant-card-expiry"] && (
							<span className="error">
								{errors["easymerchant-card-expiry"]}
							</span>
						)}
					</p>
					<p className="form-row form-row-last">
						<label htmlFor="card-cvc">
							Card Code (CVC) <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="easymerchant-card-cvc"
							name="easymerchant-card-cvc"
							className="input-text wc-credit-card-form-card-cvc"
							inputmode="numeric"
							maxlength="4"
							value={cardDetails["easymerchant-card-cvc"]}
							onChange={handleInputChange}
							required
						/>
						{errors["easymerchant-card-cvc"] && (
							<span className="error">{errors["easymerchant-card-cvc"]}</span>
						)}
					</p>
					<p className="form-row form-row-wide">
						<label htmlFor="card-holder-name">
							Cardholder Name <span className="required">*</span>
						</label>
						<input
							type="text"
							id="easymerchant-card-holder-name"
							name="easymerchant-card-holder-name"
							className="input-text wc-credit-card-form-card-holder-name"
							maxlength="20"
							autocomplete="off"
							placeholder="Enter your name"
							value={cardDetails["easymerchant-card-holder-name"]}
							onChange={handleInputChange}
							required
						/>
					</p>

					<SavePaymentMethodCheckbox />
				</fieldset>
			</div>

			<div
				style={{ display: showNewCardForm ? "none" : "block" }}
				id="img-payment-data1">
				<fieldset>
					<p className="form-row form-row-wide">
						<label htmlFor="-ccard-number">
							Card Number <span className="required">*</span>
						</label>
						<div id="-ccard-number" className="input-text">
							<select name="ccard_id" style={{ padding: "5px" }}>
								<option value="">Select your option</option>
								{cards.map((card) => (
									<option key={card.card_id} value={card.card_id}>
										{card.card_brand_name} ending in {card.cc_last_4} (expires
										{card.cc_valid_thru})
									</option>
								))}
							</select>
						</div>
					</p>
				</fieldset>
			</div>
		</div>
	);
};

const SavePaymentMethodCheckbox = () => (
	<p className="form-row-save-card">
		<input
			type="checkbox"
			name="save_payment_method"
			id="save-payment-method"
		/>
		<label htmlFor="save-payment-method">
			Save payment information to my account for future purchases
		</label>
	</p>
);
/**
 * Content component
 */
const Content = () => {
	return <PaymentFields />;
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={label} />;
};

/**
 * Easymerchant payment method config object.
 */
const Easymerchant = {
	name: "easymerchant",
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};

registerPaymentMethod(Easymerchant);
