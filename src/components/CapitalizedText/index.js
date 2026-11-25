const CapitalizeText = ({ text = "" }) => {
    if (typeof text !== "string" || !text.trim()) return null;

    const [first, ...rest] = text;
    return first.toUpperCase() + rest.join("");
};

export default CapitalizeText;